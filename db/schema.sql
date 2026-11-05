-- Schema dati di stripe-eventals (bozza)
-- Autore: Giulio Occhipinti
-- Aggiornato al: 05/11/2026
--
-- Bozza per Postgres 16 su Supabase. Usa oggetti che Supabase crea da solo:
--   - schema auth (tabella auth.users, funzioni auth.uid() e auth.jwt());
--   - schema storage (tabella storage.objects, funzione storage.foldername());
--   - ruoli anon, authenticated, service_role.
-- Su un Postgres "nudo" questi oggetti vanno simulati prima di eseguire il file.
--
-- Principi (dettagli in docs/architettura.md):
--   1. Importi sempre in centesimi interi (integer), mai float. Valuta ISO minuscola come in Stripe ('eur').
--   2. Membro e studente NON sono ruoli salvati: si derivano da memberships e student_verifications
--      al momento dell'acquisto (funzione price_roles_for). I soli ruoli salvati sono admin e staff check-in.
--   3. Le scritture che toccano soldi, posti e biglietti le fa solo il server con la chiave service_role
--      (che salta la RLS). Utenti e anonimi leggono solo ciò che le policy permettono.
--   4. La capienza è garantita dal database: contatori con CHECK, aggiornati in modo atomico.

begin;

create extension if not exists citext;      -- email e codici sconto senza distinzione maiuscole/minuscole
create extension if not exists btree_gist;  -- vincoli di esclusione su intervalli di tempo (sale, listini)

-- ---------------------------------------------------------------------------------------------------------
-- Enum
-- ---------------------------------------------------------------------------------------------------------

create type public.price_role as enum ('public', 'member', 'student');
create type public.app_role as enum ('admin', 'checkin_staff');
create type public.event_status as enum ('draft', 'published', 'cancelled', 'archived');
create type public.ticket_kind as enum ('full_pass', 'day_pass', 'paid_session');
-- included: aperta a chi ha un pass valido per quel giorno, senza prenotazione;
-- included_booking: inclusa nel pass ma con prenotazione gratuita a posti limitati;
-- paid: si compra a parte come ticket_type di tipo paid_session.
create type public.session_access as enum ('included', 'included_booking', 'paid');
create type public.order_status as enum (
  'pending',            -- Checkout Session creata, posti in riserva
  'processing',         -- sessione completata con pagamento asincrono non ancora confermato
  'paid',
  'expired',            -- Checkout Session scaduta, posti rilasciati
  'failed',             -- pagamento asincrono fallito
  'cancelled',          -- annullato dall'utente o dall'admin prima del pagamento
  'partially_refunded',
  'refunded'
);
create type public.hold_status as enum ('active', 'awaiting_payment', 'converted', 'released');
create type public.ticket_status as enum ('valid', 'cancelled', 'refunded', 'disputed');
create type public.booking_status as enum ('booked', 'cancelled');
create type public.waitlist_status as enum ('waiting', 'offered', 'accepted', 'expired', 'cancelled');
-- Stati di una subscription Stripe, copiati tali e quali.
create type public.membership_status as enum (
  'incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'
);
create type public.verification_method as enum ('university_email', 'document');
create type public.verification_status as enum (
  'pending_email',   -- in attesa del clic sul link inviato alla email universitaria
  'pending_review',  -- documento caricato, in attesa di un admin
  'approved',
  'rejected',
  'expired',         -- approvata ma scaduta, oppure richiesta abbandonata
  'withdrawn'        -- ritirata dall'utente
);
create type public.discount_type as enum ('percent', 'amount');
create type public.refund_status as enum ('requested', 'pending', 'succeeded', 'failed', 'canceled');
create type public.refund_source as enum ('admin_panel', 'stripe_dashboard', 'dispute');
create type public.stripe_event_status as enum ('received', 'processed', 'failed', 'ignored');
create type public.checkin_result as enum ('accepted', 'duplicate', 'revoked', 'invalid', 'wrong_day');
create type public.job_status as enum ('queued', 'running', 'done', 'failed', 'dead');

-- ---------------------------------------------------------------------------------------------------------
-- Funzione di servizio: updated_at automatico
-- ---------------------------------------------------------------------------------------------------------

create function public.touch_updated_at() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

-- ---------------------------------------------------------------------------------------------------------
-- Profili e ruoli
-- ---------------------------------------------------------------------------------------------------------

create table public.profiles (
  id                  uuid primary key references auth.users (id) on delete cascade,
  email               citext not null,
  full_name           text,
  -- Un solo Customer Stripe per persona: lo crea il server al primo acquisto e lo riusa.
  stripe_customer_id  text unique,
  marketing_consent   boolean not null default false,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now()
);
create trigger profiles_touch before update on public.profiles
  for each row execute function public.touch_updated_at();

-- Solo i ruoli "di permesso". Membro e studente si derivano (vedi price_roles_for).
-- event_id null = ruolo globale; per checkin_staff si può limitare a un evento.
create table public.user_roles (
  user_id     uuid not null references public.profiles (id) on delete cascade,
  role        public.app_role not null,
  event_id    uuid,  -- FK aggiunta dopo la tabella events
  granted_by  uuid references public.profiles (id) on delete set null,
  granted_at  timestamptz not null default now(),
  constraint user_roles_unique unique nulls not distinct (user_id, role, event_id)
);

-- ---------------------------------------------------------------------------------------------------------
-- Eventi e agenda: evento -> giorni -> tracce/sale -> sessioni -> sottosezioni
-- ---------------------------------------------------------------------------------------------------------

create table public.events (
  id                         uuid primary key default gen_random_uuid(),
  slug                       text not null unique check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  title                      text not null,
  summary                    text,
  description                text,
  status                     public.event_status not null default 'draft',
  timezone                   text not null default 'Europe/Rome',
  starts_on                  date not null,
  ends_on                    date not null,
  venue_name                 text,
  venue_address              text,
  currency                   text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  sales_open_at              timestamptz,
  sales_close_at             timestamptz,
  -- Fino a quante ore prima dell'inizio si può cambiare il nominativo di un biglietto.
  name_change_deadline_hours integer not null default 48 check (name_change_deadline_hours >= 0),
  created_at                 timestamptz not null default now(),
  updated_at                 timestamptz not null default now(),
  check (ends_on >= starts_on),
  check (sales_close_at is null or sales_open_at is null or sales_close_at > sales_open_at)
);
create trigger events_touch before update on public.events
  for each row execute function public.touch_updated_at();

alter table public.user_roles
  add constraint user_roles_event_fk foreign key (event_id) references public.events (id) on delete cascade;

create table public.event_days (
  id        uuid primary key default gen_random_uuid(),
  event_id  uuid not null references public.events (id) on delete cascade,
  day       date not null,
  label     text,
  unique (event_id, day),
  -- Serve come destinazione delle FK composte: garantisce che giorno e sessione siano dello stesso evento.
  unique (id, event_id)
);

create table public.rooms (
  id                 uuid primary key default gen_random_uuid(),
  event_id           uuid not null references public.events (id) on delete cascade,
  name               text not null,
  physical_capacity  integer check (physical_capacity is null or physical_capacity > 0),
  unique (event_id, name),
  unique (id, event_id)
);

create table public.tracks (
  id        uuid primary key default gen_random_uuid(),
  event_id  uuid not null references public.events (id) on delete cascade,
  name      text not null,
  color     text check (color is null or color ~ '^#[0-9a-fA-F]{6}$'),
  position  integer not null default 0,
  unique (event_id, name),
  unique (id, event_id)
);

-- Un "pool" è un insieme di posti condiviso. Più tipi di biglietto possono consumare lo stesso pool
-- (es. pass intero e pass giornaliero consumano entrambi il pool del giorno 1) e un tipo può consumarne
-- più d'uno (il pass intero consuma i pool di tutti i giorni). Le sessioni a posti limitati hanno il loro pool.
-- held + confirmed <= capacity è il vincolo che impedisce l'overselling: lo fa rispettare il database.
create table public.capacity_pools (
  id          uuid primary key default gen_random_uuid(),
  event_id    uuid not null references public.events (id) on delete cascade,
  name        text not null,
  capacity    integer not null check (capacity >= 0),
  held        integer not null default 0 check (held >= 0),       -- in riserva durante il checkout
  confirmed   integer not null default 0 check (confirmed >= 0),  -- venduti o prenotati
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  unique (event_id, name),
  unique (id, event_id),
  -- Se l'admin prova a ridurre la capienza sotto i posti già venduti, l'UPDATE fallisce: è voluto.
  constraint capacity_pools_no_oversell check (held + confirmed <= capacity)
);
create trigger capacity_pools_touch before update on public.capacity_pools
  for each row execute function public.touch_updated_at();

create table public.sessions (
  id                uuid primary key default gen_random_uuid(),
  event_id          uuid not null references public.events (id) on delete cascade,
  event_day_id      uuid not null,
  room_id           uuid,
  track_id          uuid,
  slug              text not null check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  title             text not null,
  abstract          text,
  format            text not null default 'talk'
                    check (format in ('keynote', 'talk', 'panel', 'workshop', 'masterclass', 'break', 'other')),
  access            public.session_access not null default 'included',
  capacity_pool_id  uuid,
  starts_at         timestamptz not null,
  ends_at           timestamptz not null,
  published         boolean not null default false,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  unique (event_id, slug),
  unique (id, event_id),
  check (ends_at > starts_at),
  -- Le sessioni a posti limitati (prenotabili o a pagamento) devono avere un pool.
  check (access = 'included' or capacity_pool_id is not null),
  foreign key (event_day_id, event_id) references public.event_days (id, event_id) on delete cascade,
  foreign key (room_id, event_id) references public.rooms (id, event_id),
  foreign key (track_id, event_id) references public.tracks (id, event_id),
  foreign key (capacity_pool_id, event_id) references public.capacity_pools (id, event_id),
  -- Due sessioni non possono occupare la stessa sala nello stesso momento.
  constraint sessions_no_room_overlap
    exclude using gist (room_id with =, tstzrange(starts_at, ends_at) with &&)
    where (room_id is not null)
);
create index sessions_event_day_idx on public.sessions (event_day_id, starts_at);
create trigger sessions_touch before update on public.sessions
  for each row execute function public.touch_updated_at();

-- Sottosezioni: i talk di un panel, i moduli di un workshop. Orari facoltativi.
create table public.session_parts (
  id          uuid primary key default gen_random_uuid(),
  session_id  uuid not null references public.sessions (id) on delete cascade,
  position    integer not null check (position >= 0),
  title       text not null,
  abstract    text,
  starts_at   timestamptz,
  ends_at     timestamptz,
  unique (session_id, position),
  unique (id, session_id),
  check (ends_at is null or starts_at is null or ends_at > starts_at)
);

create table public.speakers (
  id          uuid primary key default gen_random_uuid(),
  slug        text not null unique check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  full_name   text not null,
  headline    text,
  bio         text,
  -- Percorso nel bucket pubblico "speaker-photos" (solo foto approvate per la pubblicazione).
  photo_path  text,
  website     text,
  links       jsonb not null default '{}'::jsonb,
  -- Facoltativo: lo speaker può avere un account per aggiornare la propria scheda.
  profile_id  uuid unique references public.profiles (id) on delete set null,
  published   boolean not null default false,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
create trigger speakers_touch before update on public.speakers
  for each row execute function public.touch_updated_at();

-- Uno speaker parla in una sessione, oppure in una sua sottosezione (session_part_id valorizzato).
create table public.session_speakers (
  id               uuid primary key default gen_random_uuid(),
  session_id       uuid not null references public.sessions (id) on delete cascade,
  session_part_id  uuid,
  speaker_id       uuid not null references public.speakers (id) on delete cascade,
  role             text not null default 'speaker' check (role in ('speaker', 'moderator', 'host')),
  position         integer not null default 0,
  -- La sottosezione deve appartenere alla stessa sessione.
  foreign key (session_part_id, session_id) references public.session_parts (id, session_id) on delete cascade,
  constraint session_speakers_unique unique nulls not distinct (session_id, session_part_id, speaker_id)
);
create index session_speakers_speaker_idx on public.session_speakers (speaker_id);

-- ---------------------------------------------------------------------------------------------------------
-- Cosa si vende: tipi di biglietto e listini per ruolo
-- ---------------------------------------------------------------------------------------------------------

-- Regola d'accesso (nel codice, non in tabella): il full_pass dà le sessioni included/included_booking di tutti
-- i giorni, il day_pass quelle del suo giorno, il paid_session solo la sua sessione.
create table public.ticket_types (
  id                 uuid primary key default gen_random_uuid(),
  event_id           uuid not null references public.events (id) on delete cascade,
  kind               public.ticket_kind not null,
  name               text not null,
  description        text,
  event_day_id       uuid,
  session_id         uuid,
  -- Prodotto Stripe corrispondente: i prezzi si passano con price_data (vedi architettura.md).
  stripe_product_id  text unique,
  max_per_order      integer not null default 10 check (max_per_order between 1 and 50),
  sales_start        timestamptz,
  sales_end          timestamptz,
  active             boolean not null default true,
  position           integer not null default 0,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),
  unique (event_id, name),
  unique (id, event_id),
  foreign key (event_day_id, event_id) references public.event_days (id, event_id),
  foreign key (session_id, event_id) references public.sessions (id, event_id),
  check (
    (kind = 'full_pass'    and event_day_id is null     and session_id is null) or
    (kind = 'day_pass'     and event_day_id is not null and session_id is null) or
    (kind = 'paid_session' and session_id is not null)
  ),
  check (sales_end is null or sales_start is null or sales_end > sales_start)
);
create trigger ticket_types_touch before update on public.ticket_types
  for each row execute function public.touch_updated_at();

-- Quali pool consuma ogni tipo di biglietto (1 posto per pool per biglietto).
create table public.ticket_type_pools (
  ticket_type_id  uuid not null references public.ticket_types (id) on delete cascade,
  pool_id         uuid not null references public.capacity_pools (id) on delete restrict,
  primary key (ticket_type_id, pool_id)
);
create index ticket_type_pools_pool_idx on public.ticket_type_pools (pool_id);

-- Prezzo per (tipo di biglietto, ruolo), con finestre temporali (early bird, ultimo minuto).
-- Il vincolo di esclusione impedisce due prezzi validi nello stesso istante per la stessa coppia.
-- Se manca il prezzo member o student per un tipo, quel ruolo paga il prezzo public.
create table public.ticket_prices (
  id              uuid primary key default gen_random_uuid(),
  ticket_type_id  uuid not null references public.ticket_types (id) on delete cascade,
  price_role      public.price_role not null,
  amount_cents    integer not null check (amount_cents >= 0),
  currency        text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  label           text,  -- es. "Early bird"
  valid_from      timestamptz not null default '-infinity',
  valid_to        timestamptz not null default 'infinity',
  created_at      timestamptz not null default now(),
  check (valid_to > valid_from),
  constraint ticket_prices_no_overlap
    exclude using gist (ticket_type_id with =, price_role with =, tstzrange(valid_from, valid_to) with &&)
);

-- ---------------------------------------------------------------------------------------------------------
-- Membership (abbonamento Stripe)
-- ---------------------------------------------------------------------------------------------------------

-- Configurazione del piano: il Price ricorrente esiste in Stripe (serve al Customer Portal).
create table public.membership_plans (
  id               uuid primary key default gen_random_uuid(),
  name             text not null unique,
  stripe_price_id  text not null unique,
  amount_cents     integer not null check (amount_cents >= 0),
  currency         text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  billing_interval text not null check (billing_interval in ('month', 'year')),
  active           boolean not null default true,
  created_at       timestamptz not null default now()
);

-- Copia locale della subscription Stripe, aggiornata solo dai webhook (Stripe è la fonte; noi leggiamo questa).
create table public.memberships (
  id                       uuid primary key default gen_random_uuid(),
  profile_id               uuid not null references public.profiles (id) on delete restrict,
  plan_id                  uuid references public.membership_plans (id),
  stripe_subscription_id   text not null unique,
  stripe_customer_id       text not null,
  status                   public.membership_status not null,
  current_period_start     timestamptz,
  current_period_end       timestamptz,
  cancel_at_period_end     boolean not null default false,
  canceled_at              timestamptz,
  ended_at                 timestamptz,
  -- Data di creazione dell'ultimo evento Stripe applicato: un evento più vecchio arrivato in ritardo
  -- non deve sovrascrivere uno più nuovo.
  last_stripe_event_at     timestamptz not null,
  created_at               timestamptz not null default now(),
  updated_at               timestamptz not null default now()
);
create index memberships_profile_idx on public.memberships (profile_id, status, current_period_end);
create trigger memberships_touch before update on public.memberships
  for each row execute function public.touch_updated_at();

-- ---------------------------------------------------------------------------------------------------------
-- Verifica studenti
-- ---------------------------------------------------------------------------------------------------------

-- Domini accettati per la verifica via email (es. studenti.unipd.it). Gestiti dall'admin.
create table public.university_domains (
  domain            citext primary key check (domain ~ '^[a-z0-9.-]+\.[a-z]{2,}$'),
  institution_name  text not null,
  active            boolean not null default true
);

-- Minimizzazione: del documento si conservano solo tipo, esito, date e revisore.
-- Il file sta nel bucket privato "student-docs" e viene cancellato dopo l'esito (document_deleted_at).
create table public.student_verifications (
  id                      uuid primary key default gen_random_uuid(),
  profile_id              uuid not null references public.profiles (id) on delete cascade,
  method                  public.verification_method not null,
  status                  public.verification_status not null,
  university_email        citext,
  -- Hash SHA-256 del token inviato per email: il token in chiaro non si salva.
  email_token_hash        bytea,
  email_token_expires_at  timestamptz,
  document_path           text,  -- "<profile_id>/<verification_id>.<ext>" nel bucket privato
  document_kind           text check (document_kind is null or document_kind in
                                     ('certificato_iscrizione', 'libretto', 'tesserino', 'altro')),
  document_deleted_at     timestamptz,
  submitted_at            timestamptz not null default now(),
  reviewed_by             uuid references public.profiles (id) on delete set null,
  reviewed_at             timestamptz,
  rejection_reason        text,
  valid_until             date,  -- fine validità dello sconto studente (es. fine anno accademico)
  created_at              timestamptz not null default now(),
  updated_at              timestamptz not null default now(),
  check (method <> 'university_email' or university_email is not null),
  check (method <> 'document' or status = 'withdrawn' or document_path is not null or document_deleted_at is not null),
  check (status <> 'approved' or valid_until is not null)
);
-- Al massimo una richiesta aperta per persona.
create unique index student_verifications_one_open
  on public.student_verifications (profile_id)
  where status in ('pending_email', 'pending_review');
create index student_verifications_review_idx
  on public.student_verifications (status, submitted_at)
  where status = 'pending_review';
create trigger student_verifications_touch before update on public.student_verifications
  for each row execute function public.touch_updated_at();

-- ---------------------------------------------------------------------------------------------------------
-- Codici sconto: regole qui, applicazione in Stripe (coupon + promotion code speculari)
-- ---------------------------------------------------------------------------------------------------------

-- Le regole che Stripe non sa esprimere (ruoli ammessi, cumulo con il prezzo di ruolo) le controlla il server
-- leggendo questa tabella; poi passa a Checkout il promotion code Stripe corrispondente.
create table public.discount_codes (
  id                          uuid primary key default gen_random_uuid(),
  code                        citext not null unique check (length(code) between 6 and 40),
  description                 text,
  event_id                    uuid references public.events (id) on delete cascade,  -- null = tutti gli eventi
  discount_type               public.discount_type not null,
  percent_off                 numeric(5,2) check (percent_off is null or (percent_off > 0 and percent_off <= 100)),
  amount_off_cents            integer check (amount_off_cents is null or amount_off_cents > 0),
  currency                    text check (currency is null or currency ~ '^[a-z]{3}$'),
  eligible_roles              public.price_role[] not null default '{public,member,student}',
  -- false (consigliato): si applica il migliore tra prezzo di ruolo e prezzo pubblico scontato, mai entrambi.
  combinable_with_role_price  boolean not null default false,
  applies_to_membership       boolean not null default false,
  max_redemptions             integer check (max_redemptions is null or max_redemptions > 0),
  per_user_limit              integer check (per_user_limit is null or per_user_limit > 0),
  redeemed_count              integer not null default 0 check (redeemed_count >= 0),
  valid_from                  timestamptz,
  valid_to                    timestamptz,
  active                      boolean not null default true,
  stripe_coupon_id            text,
  stripe_promotion_code_id    text unique,
  created_by                  uuid references public.profiles (id) on delete set null,
  created_at                  timestamptz not null default now(),
  updated_at                  timestamptz not null default now(),
  check (
    (discount_type = 'percent' and percent_off is not null and amount_off_cents is null) or
    (discount_type = 'amount'  and amount_off_cents is not null and percent_off is null and currency is not null)
  ),
  check (max_redemptions is null or redeemed_count <= max_redemptions),
  check (valid_to is null or valid_from is null or valid_to > valid_from)
);
create trigger discount_codes_touch before update on public.discount_codes
  for each row execute function public.touch_updated_at();

-- Nessuna riga = il codice vale per tutti i tipi di biglietto dell'evento.
create table public.discount_code_ticket_types (
  discount_code_id  uuid not null references public.discount_codes (id) on delete cascade,
  ticket_type_id    uuid not null references public.ticket_types (id) on delete cascade,
  primary key (discount_code_id, ticket_type_id)
);

-- ---------------------------------------------------------------------------------------------------------
-- Ordini, riserve di posti, biglietti
-- ---------------------------------------------------------------------------------------------------------

create table public.orders (
  id                          uuid primary key default gen_random_uuid(),
  event_id                    uuid not null references public.events (id) on delete restrict,
  profile_id                  uuid not null references public.profiles (id) on delete restrict,
  buyer_email                 citext not null,
  status                      public.order_status not null default 'pending',
  -- Ruolo di prezzo "fotografato" alla creazione della Checkout Session: non cambia dopo.
  price_role                  public.price_role not null,
  currency                    text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  subtotal_cents              integer not null check (subtotal_cents >= 0),
  discount_cents              integer not null default 0 check (discount_cents >= 0),
  total_cents                 integer not null check (total_cents >= 0),
  -- Imposte: restano null finché non è decisa la gestione fiscale (domanda aperta).
  tax_cents                   integer check (tax_cents is null or tax_cents >= 0),
  discount_code_id            uuid references public.discount_codes (id) on delete set null,
  stripe_checkout_session_id  text unique,
  stripe_payment_intent_id    text unique,
  checkout_expires_at         timestamptz,
  paid_at                     timestamptz,
  livemode                    boolean not null,
  -- Importo effettivamente incassato secondo Stripe (amount_total): la riconciliazione lo confronta con total_cents.
  stripe_amount_total_cents   integer,
  created_at                  timestamptz not null default now(),
  updated_at                  timestamptz not null default now(),
  check (total_cents = subtotal_cents - discount_cents),
  check (status <> 'paid' or paid_at is not null)
);
create index orders_profile_idx on public.orders (profile_id, created_at desc);
create index orders_status_idx on public.orders (status, checkout_expires_at) where status in ('pending', 'processing');
create trigger orders_touch before update on public.orders
  for each row execute function public.touch_updated_at();

create table public.order_items (
  id                 uuid primary key default gen_random_uuid(),
  order_id           uuid not null references public.orders (id) on delete cascade,
  ticket_type_id     uuid not null references public.ticket_types (id) on delete restrict,
  quantity           integer not null check (quantity between 1 and 50),
  -- Ruolo applicato a questa riga: con la regola "prezzo di ruolo solo per il biglietto dell'acquirente"
  -- un ordine può avere una riga member (quantità 1) e una riga public per gli accompagnatori.
  price_role         public.price_role not null,
  unit_amount_cents  integer not null check (unit_amount_cents >= 0),
  discount_cents     integer not null default 0 check (discount_cents >= 0),
  total_cents        integer not null check (total_cents >= 0),
  ticket_price_id    uuid references public.ticket_prices (id) on delete set null,
  -- Nominativi raccolti nel carrello, prima del pagamento: [{"name": "...", "email": "..."}], uno per unità.
  -- Al pagamento confermato diventano righe di tickets.
  attendees          jsonb not null default '[]'::jsonb,
  check (total_cents = unit_amount_cents * quantity - discount_cents),
  check (jsonb_typeof(attendees) = 'array' and jsonb_array_length(attendees) = quantity)
);
create index order_items_order_idx on public.order_items (order_id);

-- Riserva temporanea dei posti, una riga per (ordine, pool).
-- expires_at = scadenza della Checkout Session + margine; null quando il pagamento è asincrono in corso.
create table public.capacity_holds (
  id              uuid primary key default gen_random_uuid(),
  order_id        uuid not null references public.orders (id) on delete cascade,
  pool_id         uuid not null references public.capacity_pools (id) on delete restrict,
  quantity        integer not null check (quantity > 0),
  status          public.hold_status not null default 'active',
  expires_at      timestamptz,
  created_at      timestamptz not null default now(),
  released_at     timestamptz,
  release_reason  text,
  unique (order_id, pool_id),
  check (status <> 'active' or expires_at is not null),
  check (status <> 'released' or released_at is not null)
);
create index capacity_holds_expiry_idx on public.capacity_holds (expires_at) where status = 'active';

-- Un biglietto per partecipante, nominativo.
-- Il QR contiene id, evento e qr_version firmati con una chiave Ed25519 del server (la privata solo in env);
-- le app di check-in hanno solo la pubblica. Cambiare nominativo incrementa qr_version e invalida il QR vecchio.
create table public.tickets (
  id                   uuid primary key default gen_random_uuid(),
  event_id             uuid not null references public.events (id) on delete restrict,
  order_id             uuid not null references public.orders (id) on delete restrict,
  order_item_id        uuid not null references public.order_items (id) on delete restrict,
  ticket_type_id       uuid not null references public.ticket_types (id) on delete restrict,
  price_role           public.price_role not null,
  attendee_name        text not null,
  attendee_email       citext not null,
  attendee_profile_id  uuid references public.profiles (id) on delete set null,
  status               public.ticket_status not null default 'valid',
  qr_version           integer not null default 1 check (qr_version >= 1),
  qr_key_id            text not null,  -- quale chiave ha firmato il QR (rotazione delle chiavi)
  -- Codice breve per la ricerca manuale al desk (alfabeto senza caratteri ambigui), non sostituisce il QR.
  short_code           text not null unique check (short_code ~ '^[A-HJ-NP-Z2-9]{8}$'),
  issued_at            timestamptz not null default now(),
  cancelled_at         timestamptz,
  name_changes_count   integer not null default 0 check (name_changes_count >= 0),
  created_at           timestamptz not null default now(),
  updated_at           timestamptz not null default now()
);
create index tickets_order_idx on public.tickets (order_id);
create index tickets_event_status_idx on public.tickets (event_id, status);
create index tickets_attendee_profile_idx on public.tickets (attendee_profile_id) where attendee_profile_id is not null;
create trigger tickets_touch before update on public.tickets
  for each row execute function public.touch_updated_at();

-- Prenotazione gratuita di una sessione included_booking con un biglietto valido.
create table public.session_bookings (
  id            uuid primary key default gen_random_uuid(),
  session_id    uuid not null references public.sessions (id) on delete cascade,
  ticket_id     uuid not null references public.tickets (id) on delete cascade,
  status        public.booking_status not null default 'booked',
  created_at    timestamptz not null default now(),
  cancelled_at  timestamptz,
  check (status <> 'cancelled' or cancelled_at is not null)
);
create unique index session_bookings_one_active
  on public.session_bookings (session_id, ticket_id) where status = 'booked';

-- Lista d'attesa (opzionale): quando si liberano posti in un pool, un job offre il posto al primo in fila
-- per un tempo limitato (offered_until).
create table public.waitlist_entries (
  id             uuid primary key default gen_random_uuid(),
  pool_id        uuid not null references public.capacity_pools (id) on delete cascade,
  profile_id     uuid not null references public.profiles (id) on delete cascade,
  quantity       integer not null default 1 check (quantity between 1 and 10),
  status         public.waitlist_status not null default 'waiting',
  offered_until  timestamptz,
  created_at     timestamptz not null default now(),
  check (status <> 'offered' or offered_until is not null)
);
create unique index waitlist_one_open
  on public.waitlist_entries (pool_id, profile_id) where status in ('waiting', 'offered');
create index waitlist_queue_idx on public.waitlist_entries (pool_id, created_at) where status = 'waiting';

-- Check-in. L'id lo genera il dispositivo: se la sincronizzazione offline ripete l'invio, l'insert è idempotente.
-- device_result è l'esito mostrato sul posto, server_result quello definitivo dopo la sincronizzazione.
create table public.checkins (
  id             uuid primary key,
  ticket_id      uuid references public.tickets (id) on delete set null,
  event_id       uuid not null references public.events (id) on delete cascade,
  event_day_id   uuid references public.event_days (id) on delete cascade,
  session_id     uuid references public.sessions (id) on delete cascade,  -- null = ingresso principale
  qr_version     integer,
  scanned_at     timestamptz not null,   -- orologio del dispositivo
  received_at    timestamptz not null default now(),
  device_id      text not null,
  staff_id       uuid references public.profiles (id) on delete set null,
  device_result  public.checkin_result not null,
  server_result  public.checkin_result
);
-- Un solo ingresso accettato per biglietto, per giorno e per varco (ingresso o sessione).
create unique index checkins_one_accepted
  on public.checkins (ticket_id, event_day_id, session_id) nulls not distinct
  where server_result = 'accepted';
create index checkins_event_idx on public.checkins (event_id, scanned_at);

-- ---------------------------------------------------------------------------------------------------------
-- Rimborsi
-- ---------------------------------------------------------------------------------------------------------

-- Un rimborso chiesto dal pannello ha ticket_id e idempotency key; uno fatto dalla Dashboard Stripe
-- arriva solo dal webhook (source = 'stripe_dashboard') e finisce nella coda "da riconciliare".
create table public.refunds (
  id                uuid primary key default gen_random_uuid(),
  order_id          uuid not null references public.orders (id) on delete restrict,
  ticket_id         uuid references public.tickets (id) on delete set null,
  amount_cents      integer not null check (amount_cents > 0),
  currency          text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  reason            text,
  status            public.refund_status not null default 'requested',
  source            public.refund_source not null,
  stripe_refund_id  text unique,
  idempotency_key   text unique,
  needs_review      boolean not null default false,
  requested_by      uuid references public.profiles (id) on delete set null,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);
create index refunds_order_idx on public.refunds (order_id);
create index refunds_review_idx on public.refunds (created_at) where needs_review;
create trigger refunds_touch before update on public.refunds
  for each row execute function public.touch_updated_at();

-- ---------------------------------------------------------------------------------------------------------
-- Webhook Stripe (idempotenza), job in background, audit, rate limit
-- ---------------------------------------------------------------------------------------------------------

-- L'id dell'evento Stripe è la chiave: INSERT ... ON CONFLICT DO NOTHING rende innocui i reinvii.
-- payload contiene dati personali: un job lo azzera dopo 90 giorni e lascia solo i metadati.
create table public.stripe_events (
  id                 text primary key check (id like 'evt_%'),
  type               text not null,
  livemode           boolean not null,
  api_version        text,
  stripe_created_at  timestamptz not null,
  received_at        timestamptz not null default now(),
  status             public.stripe_event_status not null default 'received',
  attempts           integer not null default 0,
  last_error         text,
  processed_at       timestamptz,
  object_id          text,   -- es. cs_..., sub_..., ch_...: utile per cercare tutti gli eventi di un oggetto
  payload            jsonb
);
create index stripe_events_object_idx on public.stripe_events (object_id, stripe_created_at);
create index stripe_events_pending_idx on public.stripe_events (received_at) where status in ('received', 'failed');

-- Coda dei lavori pesanti (email, PDF, riconciliazione, cancellazione documenti).
-- Il webhook e le azioni utente scrivono qui; un worker su cron li esegue. dedupe_key evita doppioni.
create table public.jobs (
  id            bigint generated always as identity primary key,
  kind          text not null,
  payload       jsonb not null default '{}'::jsonb,
  dedupe_key    text unique,
  status        public.job_status not null default 'queued',
  run_at        timestamptz not null default now(),
  attempts      integer not null default 0,
  max_attempts  integer not null default 8,
  last_error    text,
  locked_at     timestamptz,
  locked_by     text,
  created_at    timestamptz not null default now(),
  finished_at   timestamptz
);
create index jobs_ready_idx on public.jobs (run_at) where status = 'queued';

-- Registro solo in aggiunta: il trigger sotto vieta UPDATE e DELETE anche al service_role.
create table public.audit_log (
  id           bigint generated always as identity primary key,
  occurred_at  timestamptz not null default now(),
  actor_id     uuid,  -- niente FK: il log sopravvive alla cancellazione del profilo
  actor_type   text not null check (actor_type in ('user', 'admin', 'staff', 'system', 'stripe')),
  action       text not null,      -- es. 'ticket.name_changed', 'refund.requested', 'student.approved'
  entity_type  text not null,
  entity_id    text,
  ip           inet,
  user_agent   text,
  data         jsonb not null default '{}'::jsonb
);
create index audit_log_entity_idx on public.audit_log (entity_type, entity_id, occurred_at desc);
create index audit_log_actor_idx on public.audit_log (actor_id, occurred_at desc);

create function public.audit_log_append_only() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'audit_log è solo in aggiunta';
end;
$$;
create trigger audit_log_no_update before update or delete on public.audit_log
  for each row execute function public.audit_log_append_only();

-- Rate limit a finestra fissa, senza servizi esterni (alternativa: Vercel WAF o Upstash, vedi architettura.md).
create table public.rate_limits (
  key           text not null,         -- es. 'discount:ip:203.0.113.7' o 'checkout:user:<uuid>'
  window_start  timestamptz not null,
  hits          integer not null default 0,
  primary key (key, window_start)
);

-- ---------------------------------------------------------------------------------------------------------
-- Funzioni di dominio
-- ---------------------------------------------------------------------------------------------------------

create function public.is_admin() returns boolean
language sql stable security definer
set search_path = ''
as $$
  select exists (
    select 1 from public.user_roles r
    where r.user_id = auth.uid() and r.role = 'admin' and r.event_id is null
  );
$$;

-- Admin con secondo fattore: le policy di scrittura dell'admin richiedono aal2 (MFA di Supabase Auth).
create function public.is_admin_mfa() returns boolean
language sql stable security definer
set search_path = ''
as $$
  select public.is_admin() and coalesce(auth.jwt() ->> 'aal', '') = 'aal2';
$$;

create function public.is_checkin_staff(p_event_id uuid) returns boolean
language sql stable security definer
set search_path = ''
as $$
  select exists (
    select 1 from public.user_roles r
    where r.user_id = auth.uid()
      and r.role in ('checkin_staff', 'admin')
      and (r.event_id is null or r.event_id = p_event_id)
  );
$$;

-- Ruoli di prezzo a cui la persona ha diritto nell'istante p_at. 'public' c'è sempre.
-- Membro: subscription active o trialing con periodo in corso. past_due NON vale (domanda aperta).
-- Studente: verifica approvata e non scaduta.
-- Il server poi sceglie per ogni tipo di biglietto il prezzo più basso tra i ruoli ammessi.
create function public.price_roles_for(p_profile_id uuid, p_at timestamptz default now())
returns public.price_role[]
language sql stable security definer
set search_path = ''
as $$
  select array_remove(array[
    'public'::public.price_role,
    case when exists (
      select 1 from public.memberships m
      where m.profile_id = p_profile_id
        and m.status in ('active', 'trialing')
        and m.current_period_end > p_at
    ) then 'member'::public.price_role end,
    case when exists (
      select 1 from public.student_verifications v
      where v.profile_id = p_profile_id
        and v.status = 'approved'
        and v.valid_until >= (p_at at time zone 'Europe/Rome')::date
    ) then 'student'::public.price_role end
  ], null);
$$;

-- Prezzo migliore per un tipo di biglietto tra i ruoli ammessi, all'istante dato.
create function public.best_price(p_ticket_type_id uuid, p_roles public.price_role[], p_at timestamptz default now())
returns table (ticket_price_id uuid, price_role public.price_role, amount_cents integer)
language sql stable
set search_path = ''
as $$
  select p.id, p.price_role, p.amount_cents
  from public.ticket_prices p
  where p.ticket_type_id = p_ticket_type_id
    and p.price_role = any (p_roles)
    and p_at >= p.valid_from and p_at < p.valid_to
  order by p.amount_cents asc, p.price_role desc
  limit 1;
$$;

-- Mette in riserva i posti di un ordine su tutti i pool coinvolti, in un'unica transazione.
-- Blocca i pool in ordine di id (evita deadlock tra due checkout concorrenti). Se un pool non basta,
-- solleva 'sold_out' e la transazione del chiamante annulla tutto.
create function public.hold_order_capacity(p_order_id uuid, p_expires_at timestamptz)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  r record;
begin
  for r in
    select ttp.pool_id, sum(oi.quantity)::integer as qty
    from public.order_items oi
    join public.ticket_type_pools ttp on ttp.ticket_type_id = oi.ticket_type_id
    where oi.order_id = p_order_id
    group by ttp.pool_id
    order by ttp.pool_id
  loop
    update public.capacity_pools
       set held = held + r.qty
     where id = r.pool_id
       and held + confirmed + r.qty <= capacity;
    if not found then
      raise exception 'sold_out' using detail = r.pool_id::text, errcode = 'P0001';
    end if;
    insert into public.capacity_holds (order_id, pool_id, quantity, status, expires_at)
    values (p_order_id, r.pool_id, r.qty, 'active', p_expires_at);
  end loop;
end;
$$;

-- Pagamento confermato: i posti passano da held a confirmed.
-- Nota: con supabase-js non si aprono transazioni su più chiamate. Il "fulfillment" completo (ordine pagato,
-- posti confermati, biglietti emessi, job email in coda) va quindi in una funzione SQL unica chiamata via RPC,
-- da scrivere nella fase di codice, che usa questa funzione al suo interno.
create function public.confirm_order_capacity(p_order_id uuid)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  h record;
begin
  for h in
    select * from public.capacity_holds
    where order_id = p_order_id and status in ('active', 'awaiting_payment')
    order by pool_id
    for update
  loop
    update public.capacity_pools
       set held = held - h.quantity, confirmed = confirmed + h.quantity
     where id = h.pool_id;
    update public.capacity_holds set status = 'converted' where id = h.id;
  end loop;
end;
$$;

-- Sessione scaduta, pagamento fallito o annullamento: i posti tornano disponibili.
create function public.release_order_capacity(p_order_id uuid, p_reason text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  h record;
begin
  for h in
    select * from public.capacity_holds
    where order_id = p_order_id and status in ('active', 'awaiting_payment')
    order by pool_id
    for update
  loop
    update public.capacity_pools set held = held - h.quantity where id = h.pool_id;
    update public.capacity_holds
       set status = 'released', released_at = now(), release_reason = p_reason
     where id = h.id;
  end loop;
end;
$$;

-- Rete di sicurezza per il webhook checkout.session.expired perso: da pg_cron ogni minuto.
-- Tocca solo le riserve 'active' scadute; quelle 'awaiting_payment' aspettano l'esito asincrono.
create function public.release_expired_holds()
returns integer
language plpgsql security definer
set search_path = ''
as $$
declare
  o record;
  n integer := 0;
begin
  for o in
    select distinct order_id from public.capacity_holds
    where status = 'active' and expires_at < now()
  loop
    perform public.release_order_capacity(o.order_id, 'expired_by_job');
    update public.orders set status = 'expired' where id = o.order_id and status = 'pending';
    n := n + 1;
  end loop;
  return n;
end;
$$;

-- Prenotazione gratuita di una sessione con posti limitati. La chiama il server dopo aver verificato
-- che il biglietto dà accesso alla sessione (pass intero dell'evento o pass del giorno giusto).
create function public.book_session(p_ticket_id uuid, p_session_id uuid)
returns uuid
language plpgsql security definer
set search_path = ''
as $$
declare
  v_pool uuid;
  v_id uuid;
begin
  select s.capacity_pool_id into v_pool
  from public.sessions s
  where s.id = p_session_id and s.access = 'included_booking';
  if v_pool is null then
    raise exception 'session_not_bookable' using errcode = 'P0001';
  end if;

  update public.capacity_pools
     set confirmed = confirmed + 1
   where id = v_pool and held + confirmed + 1 <= capacity;
  if not found then
    raise exception 'sold_out' using detail = v_pool::text, errcode = 'P0001';
  end if;

  insert into public.session_bookings (session_id, ticket_id)
  values (p_session_id, p_ticket_id)
  returning id into v_id;  -- l'indice unico parziale blocca la doppia prenotazione
  return v_id;
end;
$$;

-- Le funzioni che spostano posti le esegue solo il server (service_role). In Postgres EXECUTE è di PUBLIC
-- per default: va tolto esplicitamente.
revoke execute on function public.hold_order_capacity(uuid, timestamptz) from public;
revoke execute on function public.confirm_order_capacity(uuid) from public;
revoke execute on function public.release_order_capacity(uuid, text) from public;
revoke execute on function public.release_expired_holds() from public;
revoke execute on function public.book_session(uuid, uuid) from public;
revoke execute on function public.price_roles_for(uuid, timestamptz) from public;
grant execute on function public.hold_order_capacity(uuid, timestamptz) to service_role;
grant execute on function public.confirm_order_capacity(uuid) to service_role;
grant execute on function public.release_order_capacity(uuid, text) to service_role;
grant execute on function public.release_expired_holds() to service_role;
grant execute on function public.book_session(uuid, uuid) to service_role;
grant execute on function public.price_roles_for(uuid, timestamptz) to service_role;

-- ---------------------------------------------------------------------------------------------------------
-- Row Level Security
-- ---------------------------------------------------------------------------------------------------------
-- Regola: RLS attiva su TUTTE le tabelle. Senza policy una tabella è chiusa per anon e authenticated;
-- service_role la salta. Le scritture dell'admin sui contenuti passano dal suo JWT e richiedono MFA (aal2).

alter table public.profiles                   enable row level security;
alter table public.user_roles                 enable row level security;
alter table public.events                     enable row level security;
alter table public.event_days                 enable row level security;
alter table public.rooms                      enable row level security;
alter table public.tracks                     enable row level security;
alter table public.capacity_pools             enable row level security;
alter table public.sessions                   enable row level security;
alter table public.session_parts              enable row level security;
alter table public.speakers                   enable row level security;
alter table public.session_speakers           enable row level security;
alter table public.ticket_types               enable row level security;
alter table public.ticket_type_pools          enable row level security;
alter table public.ticket_prices              enable row level security;
alter table public.membership_plans           enable row level security;
alter table public.memberships                enable row level security;
alter table public.university_domains         enable row level security;
alter table public.student_verifications      enable row level security;
alter table public.discount_codes             enable row level security;
alter table public.discount_code_ticket_types enable row level security;
alter table public.orders                     enable row level security;
alter table public.order_items                enable row level security;
alter table public.capacity_holds             enable row level security;
alter table public.tickets                    enable row level security;
alter table public.session_bookings           enable row level security;
alter table public.waitlist_entries           enable row level security;
alter table public.checkins                   enable row level security;
alter table public.refunds                    enable row level security;
alter table public.stripe_events              enable row level security;
alter table public.jobs                       enable row level security;
alter table public.audit_log                  enable row level security;
alter table public.rate_limits                enable row level security;

-- Contenuti pubblici: leggibili da tutti se l'evento è pubblicato.
create policy events_public_read on public.events
  for select to anon, authenticated
  using (status in ('published', 'cancelled') or public.is_admin());

create policy event_days_public_read on public.event_days
  for select to anon, authenticated
  using (exists (select 1 from public.events e where e.id = event_id and e.status in ('published', 'cancelled'))
         or public.is_admin());

create policy rooms_public_read on public.rooms
  for select to anon, authenticated
  using (exists (select 1 from public.events e where e.id = event_id and e.status in ('published', 'cancelled'))
         or public.is_admin());

create policy tracks_public_read on public.tracks
  for select to anon, authenticated
  using (exists (select 1 from public.events e where e.id = event_id and e.status in ('published', 'cancelled'))
         or public.is_admin());

create policy sessions_public_read on public.sessions
  for select to anon, authenticated
  using ((published and exists (select 1 from public.events e
                                where e.id = event_id and e.status in ('published', 'cancelled')))
         or public.is_admin());

create policy session_parts_public_read on public.session_parts
  for select to anon, authenticated
  using (exists (select 1 from public.sessions s where s.id = session_id and s.published) or public.is_admin());

create policy speakers_public_read on public.speakers
  for select to anon, authenticated
  using (published or public.is_admin() or profile_id = auth.uid());

create policy session_speakers_public_read on public.session_speakers
  for select to anon, authenticated
  using (exists (select 1 from public.sessions s where s.id = session_id and s.published) or public.is_admin());

create policy ticket_types_public_read on public.ticket_types
  for select to anon, authenticated
  using ((active and exists (select 1 from public.events e where e.id = event_id and e.status = 'published'))
         or public.is_admin());

-- I listini sono pubblici (si mostrano anche prezzo membro e studente per invogliare a iscriversi).
create policy ticket_prices_public_read on public.ticket_prices
  for select to anon, authenticated
  using (exists (select 1 from public.ticket_types t
                 join public.events e on e.id = t.event_id
                 where t.id = ticket_type_id and t.active and e.status = 'published')
         or public.is_admin());

create policy membership_plans_public_read on public.membership_plans
  for select to anon, authenticated
  using (active or public.is_admin());

-- Scritture dei contenuti: solo admin con MFA. Una policy per tabella, per tutte le operazioni.
create policy events_admin_write on public.events for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy event_days_admin_write on public.event_days for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy rooms_admin_write on public.rooms for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy tracks_admin_write on public.tracks for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy sessions_admin_write on public.sessions for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy session_parts_admin_write on public.session_parts for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy speakers_admin_write on public.speakers for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy session_speakers_admin_write on public.session_speakers for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
create policy university_domains_admin_all on public.university_domains for all to authenticated
  using (public.is_admin_mfa()) with check (public.is_admin_mfa());
-- Pool, tipi di biglietto, prezzi e codici sconto toccano Stripe: li scrive il server (service_role) dopo aver
-- verificato sessione admin e MFA, così Prodotti e Promotion Code restano allineati. L'admin li può leggere.
create policy capacity_pools_admin_read on public.capacity_pools for select to authenticated
  using (public.is_admin());
create policy ticket_type_pools_admin_read on public.ticket_type_pools for select to authenticated
  using (public.is_admin());
create policy discount_codes_admin_read on public.discount_codes for select to authenticated
  using (public.is_admin());
create policy discount_code_ticket_types_admin_read on public.discount_code_ticket_types for select to authenticated
  using (public.is_admin());

-- Dati personali: ognuno vede i propri, l'admin tutti.
create policy profiles_self_read on public.profiles for select to authenticated
  using (id = auth.uid() or public.is_admin());
create policy profiles_self_update on public.profiles for update to authenticated
  using (id = auth.uid()) with check (id = auth.uid());
-- L'utente può cambiare solo nome e consenso marketing, non email né customer Stripe.
revoke update on public.profiles from authenticated;
grant update (full_name, marketing_consent) on public.profiles to authenticated;

create policy user_roles_self_read on public.user_roles for select to authenticated
  using (user_id = auth.uid() or public.is_admin());

create policy memberships_self_read on public.memberships for select to authenticated
  using (profile_id = auth.uid() or public.is_admin());

-- Lo studente vede lo stato delle sue richieste, ma non l'hash del token (permesso per colonna).
create policy student_verifications_self_read on public.student_verifications for select to authenticated
  using (profile_id = auth.uid() or public.is_admin());
revoke select on public.student_verifications from authenticated;
grant select (id, profile_id, method, status, university_email, document_kind, submitted_at,
              reviewed_at, rejection_reason, valid_until, created_at)
  on public.student_verifications to authenticated;

create policy orders_self_read on public.orders for select to authenticated
  using (profile_id = auth.uid() or public.is_admin());

create policy order_items_self_read on public.order_items for select to authenticated
  using (exists (select 1 from public.orders o where o.id = order_id and o.profile_id = auth.uid())
         or public.is_admin());

-- Il biglietto lo vede chi l'ha comprato e il partecipante a cui è intestato (se ha un account).
create policy tickets_self_read on public.tickets for select to authenticated
  using (attendee_profile_id = auth.uid()
         or exists (select 1 from public.orders o where o.id = order_id and o.profile_id = auth.uid())
         or public.is_admin()
         or public.is_checkin_staff(event_id));

create policy session_bookings_self_read on public.session_bookings for select to authenticated
  using (exists (select 1 from public.tickets t
                 left join public.orders o on o.id = t.order_id
                 where t.id = ticket_id
                   and (t.attendee_profile_id = auth.uid() or o.profile_id = auth.uid()))
         or public.is_admin());

create policy waitlist_self_read on public.waitlist_entries for select to authenticated
  using (profile_id = auth.uid() or public.is_admin());

create policy refunds_self_read on public.refunds for select to authenticated
  using (exists (select 1 from public.orders o where o.id = order_id and o.profile_id = auth.uid())
         or public.is_admin());

-- Check-in: lo staff vede quelli del proprio evento. Le scritture passano dall'API di sincronizzazione.
create policy checkins_staff_read on public.checkins for select to authenticated
  using (public.is_checkin_staff(event_id));

-- Tabelle tecniche: nessuna policy per anon/authenticated (solo service_role), tranne la lettura admin.
create policy capacity_holds_admin_read on public.capacity_holds for select to authenticated
  using (public.is_admin());
create policy stripe_events_admin_read on public.stripe_events for select to authenticated
  using (public.is_admin());
create policy jobs_admin_read on public.jobs for select to authenticated
  using (public.is_admin());
create policy audit_log_admin_read on public.audit_log for select to authenticated
  using (public.is_admin());
-- rate_limits: nessuna policy.

-- Difesa in profondità: anon non ha privilegi sulle tabelle con dati personali o tecnici,
-- anche se un giorno qualcuno aggiungesse per errore una policy troppo larga.
revoke all on public.profiles, public.user_roles, public.memberships, public.student_verifications,
              public.orders, public.order_items, public.capacity_holds, public.tickets,
              public.session_bookings, public.waitlist_entries, public.checkins, public.refunds,
              public.stripe_events, public.jobs, public.audit_log, public.rate_limits,
              public.discount_codes, public.discount_code_ticket_types, public.capacity_pools,
              public.ticket_type_pools
  from anon;

-- ---------------------------------------------------------------------------------------------------------
-- Storage (Supabase): bucket privato per i documenti degli studenti
-- ---------------------------------------------------------------------------------------------------------
-- Il bucket "student-docs" si crea privato (public = false), con limite di 5 MB e tipi pdf/jpeg/png.
-- L'utente può solo caricare nella propria cartella "<uid>/...": non può rileggere né elencare i file.
-- L'admin li vede solo tramite URL firmati di 60 secondi generati dal server (service_role).
-- Dopo l'esito un job cancella il file e valorizza document_deleted_at.

create policy student_docs_owner_insert on storage.objects
  for insert to authenticated
  with check (
    bucket_id = 'student-docs'
    and (storage.foldername(name))[1] = auth.uid()::text
  );

commit;
