-- Schema dati di stripe-eventals (bozza)
-- Autore: Giulio Occhipinti
-- Aggiornato al: 05/11/2026
--
-- Bozza per Postgres 16 su Supabase. Usa oggetti che Supabase crea da solo:
--   - schema auth (tabella auth.users, funzioni auth.uid() e auth.jwt());
--   - ruoli anon, authenticated, service_role.
-- Lo Storage serve solo per il bucket pubblico "speaker-photos": nessuna policy in questo file.
-- Su un Postgres "nudo" questi oggetti vanno simulati prima di eseguire il file.
--
-- Principi (dettagli in docs/architettura.md):
--   1. Importi sempre in centesimi interi (integer), mai float. Valuta ISO minuscola come in Stripe ('eur').
--   2. Membro NON è un ruolo salvato: si deriva da memberships al momento dell'acquisto (funzione
--      price_roles_for). I soli ruoli salvati sono admin e staff check-in.
--   2b. Prezzo membro solo sul biglietto del membro che compra (decisione di Giulio del 05/11/2026): righe member
--      di quantità 1, intestate all'acquirente (CHECK e trigger su order_items).
--   2c. Limite prezzo membro (decisione di Giulio del 05/11/2026): al massimo un biglietto a prezzo membro per
--      membro, per evento e per tipo (un pass intero, un giornaliero per ciascun giorno, uno per ciascuna sessione
--      a pagamento), anche su più ordini. Contano gli ordini pending, processing, paid e partially_refunded
--      (le riserve attive contano: niente due checkout in parallelo). Lo fa rispettare la tabella
--      member_price_claims con un indice unico parziale; reserve_order ricontrolla.
--   2d. Nessun rimborso self-service (decisione di Giulio del 05/11/2026, da far confermare alla catena legale):
--      rimborsi solo dall'admin per evento annullato o per ordine pagato senza posti. Cambio nominativo gratuito
--      fino a 48 ore prima dell'inizio dell'evento (trigger su tickets); il biglietto a prezzo membro non si cede.
--   3. Le scritture che toccano soldi, posti e biglietti le fa solo il server con la chiave service_role
--      (che salta la RLS). Utenti e anonimi leggono solo ciò che le policy permettono.
--   4. La capienza è garantita dal database: contatori con CHECK, aggiornati in modo atomico.

begin;

create extension if not exists citext;      -- email e codici sconto senza distinzione maiuscole/minuscole
create extension if not exists btree_gist;  -- vincoli di esclusione su intervalli di tempo (sale, listini)

-- ---------------------------------------------------------------------------------------------------------
-- Enum
-- ---------------------------------------------------------------------------------------------------------

-- Ruoli di prezzo. Il ruolo studente è stato tolto (decisione di Giulio del 05/11/2026); un ruolo nuovo si
-- aggiunge con ALTER TYPE ... ADD VALUE, una riga di ticket_prices per tipo e un ramo in price_roles_for.
create type public.price_role as enum ('public', 'member');
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
  'needs_refund',       -- pagato quando i posti non c'erano più: rimborso automatico in coda, avviso all'admin
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
create type public.discount_type as enum ('percent', 'amount');
create type public.refund_status as enum ('requested', 'pending', 'succeeded', 'failed', 'canceled');
-- automatic: il job stripe.refund_full degli ordini needs_refund.
create type public.refund_source as enum ('admin_panel', 'automatic', 'stripe_dashboard', 'dispute');
-- Nessun rimborso self-service (decisione di Giulio del 05/11/2026): si rimborsa solo in questi due casi.
create type public.refund_cause as enum ('event_cancelled', 'paid_without_capacity');
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

-- Solo i ruoli "di permesso". Membro si deriva (vedi price_roles_for).
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
  -- Fino a quante ore prima dell'inizio (event_starts_at) si può cambiare gratis il nominativo di un biglietto:
  -- 48 per decisione di Giulio del 05/11/2026. Lo fa rispettare il trigger tickets_attendee_rules.
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
  -- Limite di posti di questo pool in un solo ordine (es. 2 sui workshop piccoli), contro chi accaparra.
  max_per_order integer check (max_per_order is null or max_per_order > 0),
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
  -- Nessun Product Stripe salvato: la riga di Checkout si crea con price_data.product_data (vedi architettura.md).
  max_per_order      integer not null default 4 check (max_per_order between 1 and 50),
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
-- Se manca il prezzo member per un tipo, il membro paga il prezzo public.
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
-- Durata (decisione di Giulio del 05/11/2026): 12 mesi dall'acquisto, rinnovo automatico, annullamento dal
-- Customer Portal Stripe. Il Price è quindi ricorrente annuale (interval = year, interval_count = 1).
create table public.membership_plans (
  id               uuid primary key default gen_random_uuid(),
  name             text not null unique,
  stripe_price_id  text not null unique,
  amount_cents     integer not null check (amount_cents >= 0),
  currency         text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  billing_interval text not null default 'year' check (billing_interval = 'year'),
  active           boolean not null default true,
  created_at       timestamptz not null default now()
);

-- Copia locale della subscription Stripe, aggiornata solo dai webhook (Stripe è la fonte; noi leggiamo questa).
-- current_period_start = acquisto o ultimo rinnovo, current_period_end = 12 mesi dopo. Annullare dal Customer
-- Portal mette cancel_at_period_end = true: niente rinnovo, il prezzo membro vale fino a current_period_end.
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
-- Codici sconto: regole e calcolo solo qui, nessun Coupon o Promotion Code in Stripe
-- ---------------------------------------------------------------------------------------------------------

-- Checkout accetta un solo coupon per sessione e non distingue due righe dello stesso prodotto: la regola
-- "migliore tra prezzo di ruolo e codice, riga per riga" non si esprime con i Coupon. Il server calcola lo
-- sconto per unità e lo mette già dentro price_data.unit_amount (order_items.unit_discount_cents).
-- Gli utilizzi si contano in reserve_order con UPDATE ... WHERE redeemed_count < max_redemptions.
create table public.discount_codes (
  id                          uuid primary key default gen_random_uuid(),
  code                        citext not null unique check (length(code) between 6 and 40),
  description                 text,
  event_id                    uuid references public.events (id) on delete cascade,  -- null = tutti gli eventi
  discount_type               public.discount_type not null,
  percent_off                 numeric(5,2) check (percent_off is null or (percent_off > 0 and percent_off <= 100)),
  amount_off_cents            integer check (amount_off_cents is null or amount_off_cents > 0),
  currency                    text check (currency is null or currency ~ '^[a-z]{3}$'),
  -- Ruoli dell'acquirente che possono usare il codice (controllati con price_roles_for).
  eligible_roles              public.price_role[] not null default '{public,member}',
  -- false (consigliato): sulla riga del biglietto del membro si applica il migliore tra prezzo membro e prezzo
  -- pubblico scontato, mai entrambi; le altre righe sono a prezzo pubblico e ricevono il codice.
  combinable_with_role_price  boolean not null default false,
  applies_to_membership       boolean not null default false,
  max_redemptions             integer check (max_redemptions is null or max_redemptions > 0),
  per_user_limit              integer check (per_user_limit is null or per_user_limit > 0),
  -- Utilizzi riservati o confermati. Niente CHECK redeemed_count <= max_redemptions: il limite vale alla
  -- riserva, ma un pagamento arrivato in ritardo e già incassato si onora anche oltre il limite.
  redeemed_count              integer not null default 0 check (redeemed_count >= 0),
  valid_from                  timestamptz,
  valid_to                    timestamptz,
  active                      boolean not null default true,
  created_by                  uuid references public.profiles (id) on delete set null,
  created_at                  timestamptz not null default now(),
  updated_at                  timestamptz not null default now(),
  check (
    (discount_type = 'percent' and percent_off is not null and amount_off_cents is null) or
    (discount_type = 'amount'  and amount_off_cents is not null and percent_off is null and currency is not null)
  ),
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
  -- Ruolo di prezzo dell'acquirente "fotografato" alla creazione della Checkout Session: non cambia dopo.
  -- 'member' permette (non obbliga) una riga member in order_items.
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
  checkout_expires_at         timestamptz,  -- expires_at della Checkout Session (almeno 30 minuti, minimo Stripe)
  -- true se un utilizzo del codice sconto è stato contato per questo ordine (va restituito se l'ordine scade).
  discount_reserved           boolean not null default false,
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
-- Una sola riserva aperta per persona: prima di un nuovo checkout il server fa expire della sessione precedente.
create unique index orders_one_pending_per_profile on public.orders (profile_id) where status = 'pending';
create trigger orders_touch before update on public.orders
  for each row execute function public.touch_updated_at();

create table public.order_items (
  id                 uuid primary key default gen_random_uuid(),
  order_id           uuid not null references public.orders (id) on delete cascade,
  ticket_type_id     uuid not null references public.ticket_types (id) on delete restrict,
  quantity           integer not null check (quantity between 1 and 50),
  -- Ruolo applicato a questa riga. Prezzo membro solo sul biglietto del membro che compra: righe member di
  -- quantità 1, intestate all'acquirente (trigger order_items_member_rules), al massimo una per membro, evento e
  -- tipo anche su più ordini (member_price_claims). Accompagnatori e altri biglietti: righe public.
  price_role              public.price_role not null,
  list_unit_amount_cents  integer not null check (list_unit_amount_cents >= 0),  -- listino del ruolo
  unit_discount_cents     integer not null default 0 check (unit_discount_cents >= 0),
  -- Importo per unità inviato a Stripe in price_data.unit_amount: sconto già incluso.
  unit_amount_cents       integer not null check (unit_amount_cents >= 0),
  total_cents             integer not null check (total_cents >= 0),
  -- Testo mostrato sulla pagina Stripe e in ricevuta, es. "Pass intero · prezzo membro" o "… · codice ESTATE −20%".
  stripe_line_name        text not null,
  ticket_price_id    uuid references public.ticket_prices (id) on delete set null,
  -- Nominativi raccolti nel carrello, prima del pagamento: [{"name": "...", "email": "..."}], uno per unità.
  -- Al pagamento confermato diventano righe di tickets.
  attendees          jsonb not null default '[]'::jsonb,
  check (unit_discount_cents <= list_unit_amount_cents),
  check (unit_amount_cents = list_unit_amount_cents - unit_discount_cents),
  check (total_cents = unit_amount_cents * quantity),
  check (jsonb_typeof(attendees) = 'array' and jsonb_array_length(attendees) = quantity),
  constraint order_items_member_qty_one check (price_role <> 'member' or quantity = 1)
);
create index order_items_order_idx on public.order_items (order_id);
-- Il vecchio limite «un biglietto a prezzo membro per ordine» (indice order_items_one_member_per_order) è stato
-- sostituito dal limite per membro, evento e tipo di member_price_claims (decisione di Giulio del 05/11/2026).

-- La riga member deve essere intestata all'acquirente (stessa email) e l'acquirente deve essere membro
-- secondo il ruolo fotografato nell'ordine. Se le righe si scrivono prima dell'ordine, l'insert fallisce: è voluto.
-- Ordine, tipo di biglietto e ruolo di una riga non cambiano dopo l'insert (la prenotazione del prezzo membro
-- è legata alla riga).
create function public.order_items_member_rules() returns trigger
language plpgsql
set search_path = ''
as $$
declare
  v_role  public.price_role;
  v_email text;
begin
  if tg_op = 'UPDATE' and (new.order_id, new.ticket_type_id, new.price_role)
                          is distinct from (old.order_id, old.ticket_type_id, old.price_role) then
    raise exception 'order_item_frozen' using errcode = 'P0001';
  end if;
  if new.price_role <> 'member' then
    return new;
  end if;
  select o.price_role, o.buyer_email::text into v_role, v_email from public.orders o where o.id = new.order_id;
  if v_role is distinct from 'member' then
    raise exception 'member_price_buyer_not_member' using errcode = 'P0001';
  end if;
  -- Confronto senza maiuscole come citext, senza dipendere dallo schema in cui sta l'estensione.
  if lower(new.attendees -> 0 ->> 'email') is distinct from lower(v_email) then
    raise exception 'member_price_attendee_not_buyer' using errcode = 'P0001';
  end if;
  return new;
end;
$$;
create trigger order_items_member_rules before insert or update on public.order_items
  for each row execute function public.order_items_member_rules();

-- Limite prezzo membro (decisione di Giulio del 05/11/2026): al massimo un biglietto a prezzo membro per membro,
-- per evento e per tipo, anche su più ordini. Ogni riga member "prenota" la sua chiave:
--   full_pass    -> (membro, evento, full_pass)               un pass intero per evento;
--   day_pass     -> (membro, evento, day_pass, giorno)        un giornaliero per ciascun giorno;
--   paid_session -> (membro, evento, paid_session, sessione)  uno per ciascuna sessione a pagamento.
-- Due tipi di biglietto dello stesso genere (es. due giornalieri dello stesso giorno) contano come uno.
-- La prenotazione è attiva (released_at null) finché l'ordine è pending, processing, paid o partially_refunded;
-- si libera quando l'ordine diventa expired, failed, cancelled, needs_refund o refunded, oppure quando il
-- biglietto a prezzo membro diventa cancelled o refunded. L'indice unico parziale impedisce la seconda anche con
-- due checkout in parallelo (il secondo insert aspetta il primo e poi fallisce), senza lock espliciti.
create table public.member_price_claims (
  id              uuid primary key default gen_random_uuid(),
  profile_id      uuid not null references public.profiles (id) on delete restrict,
  event_id        uuid not null references public.events (id) on delete restrict,
  kind            public.ticket_kind not null,
  event_day_id    uuid references public.event_days (id) on delete restrict,
  session_id      uuid references public.sessions (id) on delete restrict,
  order_id        uuid not null references public.orders (id) on delete cascade,
  order_item_id   uuid not null unique references public.order_items (id) on delete cascade,
  created_at      timestamptz not null default now(),
  released_at     timestamptz,
  release_reason  text,
  check (
    (kind = 'full_pass'    and event_day_id is null     and session_id is null) or
    (kind = 'day_pass'     and event_day_id is not null and session_id is null) or
    (kind = 'paid_session' and event_day_id is null     and session_id is not null)
  )
);
create unique index member_price_claims_one_active
  on public.member_price_claims (profile_id, event_id, kind, event_day_id, session_id) nulls not distinct
  where released_at is null;
create index member_price_claims_order_idx on public.member_price_claims (order_id);

create function public.order_items_claim_member_price() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.price_role <> 'member' then
    return null;
  end if;
  begin
    insert into public.member_price_claims (profile_id, event_id, kind, event_day_id, session_id, order_id, order_item_id)
    select o.profile_id, o.event_id, t.kind,
           case when t.kind = 'day_pass' then t.event_day_id end,
           case when t.kind = 'paid_session' then t.session_id end,
           o.id, new.id
    from public.orders o
    join public.ticket_types t on t.id = new.ticket_type_id
    where o.id = new.order_id;
  exception when unique_violation then
    raise exception 'member_price_limit' using errcode = 'P0001',
      detail = 'prezzo membro già usato per questo tipo di biglietto in questo evento';
  end;
  return null;
end;
$$;
create trigger order_items_claim_member_price after insert on public.order_items
  for each row execute function public.order_items_claim_member_price();

-- Ordine chiuso senza biglietti validi: le sue prenotazioni del prezzo membro si liberano.
create function public.orders_release_member_claims() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.status in ('expired', 'failed', 'cancelled', 'needs_refund', 'refunded')
     and new.status is distinct from old.status then
    update public.member_price_claims
       set released_at = now(), release_reason = 'ordine ' || new.status
     where order_id = new.id and released_at is null;
  end if;
  return null;
end;
$$;
create trigger orders_release_member_claims after update of status on public.orders
  for each row execute function public.orders_release_member_claims();

-- Riserva temporanea dei posti, una riga per (ordine, pool).
-- expires_at = scadenza della Checkout Session + 5 minuti; null quando il pagamento è asincrono in corso.
-- Una riserva scaduta NON si libera da sola: la libera close_unpaid_order solo dopo che Stripe ha confermato
-- che la sessione è scaduta (webhook checkout.session.expired o job che chiama expire). Se il job è fermo
-- i posti restano bloccati (si vende di meno), mai venduti due volte.
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
-- Cambio nominativo = nuova email di chi partecipa (change_ticket_attendee): gratis fino a
-- events.name_change_deadline_hours (48) prima dell'inizio dell'evento; mai sui biglietti a prezzo membro.
create table public.tickets (
  id                   uuid primary key default gen_random_uuid(),
  event_id             uuid not null references public.events (id) on delete restrict,
  order_id             uuid not null references public.orders (id) on delete restrict,
  order_item_id        uuid not null references public.order_items (id) on delete restrict,
  seq                  integer not null check (seq >= 1),  -- n-esimo biglietto della riga d'ordine
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
  updated_at           timestamptz not null default now(),
  -- Seconda difesa contro l'emissione doppia: lo stesso biglietto della stessa riga non può nascere due volte.
  unique (order_item_id, seq)
);
create index tickets_order_idx on public.tickets (order_id);
create index tickets_event_status_idx on public.tickets (event_id, status);
create index tickets_attendee_profile_idx on public.tickets (attendee_profile_id) where attendee_profile_id is not null;
-- Il vecchio indice tickets_one_member_per_order (un biglietto membro per ordine) è stato tolto: ora un ordine
-- può avere un pass e un workshop a prezzo membro. Il limite vive in member_price_claims.
create trigger tickets_touch before update on public.tickets
  for each row execute function public.touch_updated_at();

-- Inizio dell'evento per la scadenza del cambio nominativo: la prima sessione pubblicata; se non ce ne sono,
-- la mezzanotte del primo giorno nel fuso dell'evento.
create function public.event_starts_at(p_event_id uuid) returns timestamptz
language sql stable
set search_path = ''
as $$
  select coalesce(
    (select min(s.starts_at) from public.sessions s where s.event_id = p_event_id and s.published),
    (select e.starts_on::timestamp at time zone e.timezone from public.events e where e.id = p_event_id)
  );
$$;

-- Regole sull'intestatario, per ogni scrittura (anche del server con service_role):
--   - biglietto a prezzo membro: l'email non cambia mai (non si cede; il solo nome si corregge per i refusi);
--   - altri biglietti: l'email cambia solo fino a name_change_deadline_hours prima dell'inizio dell'evento.
-- Il solo nome (refuso) si può correggere sempre. La regola scelta è la più semplice: il biglietto a prezzo
-- membro non si intesta a un altro, invece di "perde il prezzo membro" (che richiederebbe un pagamento).
create function public.tickets_attendee_rules() returns trigger
language plpgsql
set search_path = ''
as $$
declare
  v_hours integer;
begin
  if lower(new.attendee_email::text) is not distinct from lower(old.attendee_email::text) then
    return new;
  end if;
  if old.price_role = 'member' then
    raise exception 'member_ticket_not_transferable' using errcode = 'P0001';
  end if;
  select e.name_change_deadline_hours into v_hours from public.events e where e.id = new.event_id;
  if now() > public.event_starts_at(new.event_id) - make_interval(hours => v_hours) then
    raise exception 'name_change_deadline_passed' using errcode = 'P0001';
  end if;
  return new;
end;
$$;
create trigger tickets_attendee_rules before update of attendee_name, attendee_email on public.tickets
  for each row execute function public.tickets_attendee_rules();

-- Il biglietto a prezzo membro annullato o rimborsato libera la sua prenotazione del prezzo membro.
create function public.tickets_release_member_claim() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.price_role = 'member' and new.status in ('cancelled', 'refunded')
     and new.status is distinct from old.status then
    update public.member_price_claims
       set released_at = now(), release_reason = 'biglietto ' || new.status
     where order_item_id = new.order_item_id and released_at is null;
  end if;
  return null;
end;
$$;
create trigger tickets_release_member_claim after update of status on public.tickets
  for each row execute function public.tickets_release_member_claim();

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

-- Nessun rimborso self-service (decisione di Giulio del 05/11/2026, da far confermare alla catena legale):
-- nessuna API o policy permette all'utente di chiederlo. Il rimborso è un'azione dell'admin (admin_panel) solo
-- per evento annullato o per ordine pagato senza posti; il job automatico (automatic) solo per il secondo caso.
-- Un rimborso chiesto dal pannello ha ticket_id e idempotency key; uno fatto dalla Dashboard Stripe
-- arriva solo dal webhook (source = 'stripe_dashboard') e finisce nella coda "da riconciliare".
create table public.refunds (
  id                uuid primary key default gen_random_uuid(),
  order_id          uuid not null references public.orders (id) on delete restrict,
  ticket_id         uuid references public.tickets (id) on delete set null,
  amount_cents      integer not null check (amount_cents > 0),
  currency          text not null default 'eur' check (currency ~ '^[a-z]{3}$'),
  -- Obbligatoria per i rimborsi nati da noi; vuota per Dashboard e contestazioni (needs_review).
  cause             public.refund_cause,
  reason            text,
  status            public.refund_status not null default 'requested',
  source            public.refund_source not null,
  stripe_refund_id  text unique,
  idempotency_key   text unique,
  needs_review      boolean not null default false,
  requested_by      uuid references public.profiles (id) on delete set null,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  check (source in ('stripe_dashboard', 'dispute') or cause is not null),
  check (source <> 'automatic' or cause = 'paid_without_capacity')
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

-- Coda dei lavori pesanti (email, PDF, riconciliazione, rimborsi automatici).
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
  action       text not null,      -- es. 'ticket.name_changed', 'refund.requested', 'discount.created'
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
-- Il prezzo membro vale solo per i biglietti del membro che compra (righe di quantità 1, al massimo una per
-- evento e tipo, vedi member_price_claims): gli altri biglietti dell'ordine sono a prezzo public.
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
    ) then 'member'::public.price_role end
  ], null);
$$;

-- Prezzo migliore per un tipo di biglietto tra i ruoli ammessi, all'istante dato. Per i biglietti del membro
-- senza prenotazione attiva per quel tipo si passa {public,member}; per tutti gli altri solo {public}.
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

-- Codice breve casuale di 8 caratteri dall'alfabeto senza caratteri ambigui (32 simboli).
-- Usa i byte casuali di un UUID v4, saltando quelli con i bit di versione e variante. Non è un segreto
-- (il segreto è la firma del QR); una collisione fa fallire la transazione e Stripe ritenta il webhook.
create function public.new_short_code() returns text
language sql volatile
set search_path = ''
as $$
  select string_agg(
           substr('ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
                  (get_byte(s.b, case when g.i < 6 then g.i else g.i + 4 end) % 32) + 1, 1),
           '' order by g.i)
  from (select uuid_send(gen_random_uuid()) as b) s, generate_series(0, 7) as g(i);
$$;

-- Pool coinvolti da un ordine e quantità per pool, in ordine di id: tutte le funzioni bloccano i pool
-- sempre in quest'ordine (e sempre dopo la riga dell'ordine), così due transazioni non vanno in deadlock.
create function public.order_pool_needs(p_order_id uuid)
returns table (pool_id uuid, qty integer)
language sql stable
set search_path = ''
as $$
  select ttp.pool_id, sum(oi.quantity)::integer
  from public.order_items oi
  join public.ticket_type_pools ttp on ttp.ticket_type_id = oi.ticket_type_id
  where oi.order_id = p_order_id
  group by ttp.pool_id
  order by ttp.pool_id;
$$;

-- Mette in riserva posti e utilizzo del codice sconto di un ordine 'pending', in un'unica transazione.
-- p_session_expires_at è l'expires_at che il server passerà a Stripe: almeno 30 minuti (minimo di Stripe);
-- la riserva dura 5 minuti in più. Errori: hold_too_short, order_not_pending, already_reserved,
-- member_price_not_allowed, member_price_limit, too_many_for_pool, sold_out, discount_exhausted,
-- discount_user_limit.
-- In caso di errore non si crea la sessione.
create function public.reserve_order(p_order_id uuid, p_session_expires_at timestamptz)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_order public.orders%rowtype;
  v_code public.discount_codes%rowtype;
  r record;
begin
  if p_session_expires_at < now() + interval '30 minutes' then
    raise exception 'hold_too_short' using errcode = 'P0001';
  end if;

  select * into v_order from public.orders where id = p_order_id for update;
  if not found or v_order.status <> 'pending' then
    raise exception 'order_not_pending' using errcode = 'P0001';
  end if;
  if exists (select 1 from public.capacity_holds h where h.order_id = p_order_id) then
    raise exception 'already_reserved' using errcode = 'P0001';
  end if;
  -- Ricontrollo lato database: una riga member solo se l'acquirente è membro fino alla scadenza della sessione.
  if exists (select 1 from public.order_items oi where oi.order_id = p_order_id and oi.price_role = 'member')
     and not ('member'::public.price_role = any (public.price_roles_for(v_order.profile_id, p_session_expires_at))) then
    raise exception 'member_price_not_allowed' using errcode = 'P0001';
  end if;
  -- Ricontrollo del limite per membro, evento e tipo: ogni riga member deve avere la sua prenotazione attiva
  -- in member_price_claims (l'indice unico parziale garantisce che sia l'unica per quella chiave).
  if exists (select 1 from public.order_items oi
             where oi.order_id = p_order_id and oi.price_role = 'member'
               and not exists (select 1 from public.member_price_claims c
                               where c.order_item_id = oi.id and c.released_at is null)) then
    raise exception 'member_price_limit' using errcode = 'P0001';
  end if;

  for r in select * from public.order_pool_needs(p_order_id) loop
    if exists (select 1 from public.capacity_pools p
               where p.id = r.pool_id and p.max_per_order is not null and r.qty > p.max_per_order) then
      raise exception 'too_many_for_pool' using detail = r.pool_id::text, errcode = 'P0001';
    end if;
    update public.capacity_pools
       set held = held + r.qty
     where id = r.pool_id
       and held + confirmed + r.qty <= capacity;
    if not found then
      raise exception 'sold_out' using detail = r.pool_id::text, errcode = 'P0001';
    end if;
    insert into public.capacity_holds (order_id, pool_id, quantity, status, expires_at)
    values (p_order_id, r.pool_id, r.qty, 'active', p_session_expires_at + interval '5 minutes');
  end loop;

  if v_order.discount_code_id is not null then
    select * into v_code from public.discount_codes where id = v_order.discount_code_id;
    if v_code.per_user_limit is not null and (
         select count(*) from public.orders o
         where o.profile_id = v_order.profile_id
           and o.discount_code_id = v_order.discount_code_id
           and o.id <> p_order_id
           and o.status in ('pending', 'processing', 'paid', 'partially_refunded')
       ) >= v_code.per_user_limit then
      raise exception 'discount_user_limit' using errcode = 'P0001';
    end if;
    -- Il conteggio atomico: due ordini concorrenti non possono superare max_redemptions.
    update public.discount_codes
       set redeemed_count = redeemed_count + 1
     where id = v_order.discount_code_id
       and active
       and (max_redemptions is null or redeemed_count < max_redemptions)
       and (valid_from is null or valid_from <= now())
       and (valid_to is null or valid_to > now());
    if not found then
      raise exception 'discount_exhausted' using errcode = 'P0001';
    end if;
  end if;

  update public.orders
     set checkout_expires_at = p_session_expires_at,
         discount_reserved = (v_order.discount_code_id is not null)
   where id = p_order_id;
end;
$$;

-- Uso interno: libera riserve aperte e utilizzo del codice. La riga dell'ordine deve essere già bloccata.
create function public.release_order_reservations(p_order_id uuid, p_reason text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  h record;
  v_code uuid;
  v_reserved boolean;
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

  select discount_code_id, discount_reserved into v_code, v_reserved
  from public.orders where id = p_order_id;
  if v_reserved then
    update public.discount_codes set redeemed_count = redeemed_count - 1
     where id = v_code and redeemed_count > 0;
    update public.orders set discount_reserved = false where id = p_order_id;
  end if;
end;
$$;

-- Chiude un ordine non pagato. Da chiamare SOLO quando Stripe ha confermato che la sessione non si può più
-- pagare: webhook checkout.session.expired / async_payment_failed, oppure il job dopo una chiamata expire
-- riuscita o dopo aver letto status = 'expired'. Ammesso: pending -> expired|cancelled, pending|processing -> failed.
-- Restituisce false se l'ordine era già in un altro stato (consegna doppia, pagamento già arrivato).
create function public.close_unpaid_order(p_order_id uuid, p_new_status public.order_status, p_reason text)
returns boolean
language plpgsql security definer
set search_path = ''
as $$
declare
  v_status public.order_status;
begin
  if p_new_status not in ('expired', 'cancelled', 'failed') then
    raise exception 'invalid_target_status' using errcode = 'P0001';
  end if;
  select status into v_status from public.orders where id = p_order_id for update;
  if not found then
    raise exception 'order_not_found' using errcode = 'P0001';
  end if;
  if not (v_status = 'pending' or (v_status = 'processing' and p_new_status = 'failed')) then
    return false;
  end if;
  perform public.release_order_reservations(p_order_id, p_reason);
  update public.orders set status = p_new_status where id = p_order_id;
  return true;
end;
$$;

-- checkout.session.completed con payment_status = 'unpaid' (metodo asincrono): pending -> processing,
-- riserve senza scadenza finché arriva l'esito.
create function public.mark_order_processing(p_order_id uuid, p_session_id text)
returns boolean
language plpgsql security definer
set search_path = ''
as $$
declare
  v_order public.orders%rowtype;
begin
  select * into v_order from public.orders where id = p_order_id for update;
  if not found then
    raise exception 'order_not_found' using errcode = 'P0001';
  end if;
  if v_order.stripe_checkout_session_id is distinct from p_session_id then
    raise exception 'session_mismatch' using errcode = 'P0001';
  end if;
  if v_order.status <> 'pending' then
    return false;
  end if;
  update public.capacity_holds set status = 'awaiting_payment', expires_at = null
   where order_id = p_order_id and status = 'active';
  update public.orders set status = 'processing' where id = p_order_id;
  return true;
end;
$$;

-- Pagamento confermato. Unica funzione che emette biglietti: la chiamano il webhook
-- (checkout.session.completed pagato, async_payment_succeeded) e la pagina di successo.
-- Idempotenza: la riga dell'ordine è bloccata con FOR UPDATE, quindi due chiamate parallele si mettono in fila
-- e la seconda trova lo stato già cambiato ('already_done'). In più tickets ha unique (order_item_id, seq).
-- Transizione normale: pending|processing -> paid.
-- Pagamento tardivo (ordine già expired|failed|cancelled, riserva liberata): i soldi sono stati presi, quindi si
-- prova a riprenotare i posti. Se non ci sono più: 'needs_refund', nessun biglietto, rimborso automatico e
-- avviso all'admin in coda. Esiti: 'paid', 'paid_late', 'needs_refund', 'already_done'.
create function public.mark_order_paid(
  p_order_id           uuid,
  p_session_id         text,
  p_payment_intent_id  text,
  p_amount_total_cents integer,
  p_qr_key_id          text
)
returns text
language plpgsql security definer
set search_path = ''
as $$
declare
  v_order   public.orders%rowtype;
  v_late    boolean;
  v_soldout boolean := false;
  r         record;
  h         record;
  i         record;
  c         record;
  k         integer;
begin
  select * into v_order from public.orders where id = p_order_id for update;
  if not found then
    raise exception 'order_not_found' using errcode = 'P0001';
  end if;
  if v_order.stripe_checkout_session_id is distinct from p_session_id then
    raise exception 'session_mismatch' using errcode = 'P0001';
  end if;
  if v_order.status in ('paid', 'needs_refund', 'partially_refunded', 'refunded') then
    return 'already_done';
  end if;
  v_late := v_order.status in ('expired', 'failed', 'cancelled');

  -- Posti: si convertono le riserve ancora aperte; dove la riserva non c'è più si riprenota.
  -- Il blocco BEGIN/EXCEPTION è una sottotransazione: se un pool è esaurito, tutto ciò che il ciclo
  -- ha fatto viene annullato e nessun posto resta contato a metà.
  begin
    for r in select * from public.order_pool_needs(p_order_id) loop
      select * into h from public.capacity_holds
       where order_id = p_order_id and pool_id = r.pool_id
         and status in ('active', 'awaiting_payment')
       for update;
      if found then
        update public.capacity_pools
           set held = held - h.quantity, confirmed = confirmed + h.quantity
         where id = r.pool_id;
        update public.capacity_holds set status = 'converted', expires_at = null where id = h.id;
      else
        update public.capacity_pools
           set confirmed = confirmed + r.qty
         where id = r.pool_id and held + confirmed + r.qty <= capacity;
        if not found then
          raise exception 'sold_out_late' using errcode = 'P0001';
        end if;
        update public.capacity_holds
           set status = 'converted', expires_at = null,
               release_reason = coalesce(release_reason || '; ', '') || 'riprenotata al pagamento tardivo'
         where order_id = p_order_id and pool_id = r.pool_id;
        if not found then
          insert into public.capacity_holds (order_id, pool_id, quantity, status)
          values (p_order_id, r.pool_id, r.qty, 'converted');
        end if;
      end if;
    end loop;
  exception when sqlstate 'P0001' then
    if sqlerrm <> 'sold_out_late' then
      raise;
    end if;
    v_soldout := true;
  end;

  if v_soldout then
    -- Nessun posto contato, nessun biglietto. Le riserve ancora aperte (caso raro) si liberano.
    perform public.release_order_reservations(p_order_id, 'pagato senza posti: rimborso');
    update public.orders
       set status = 'needs_refund',
           stripe_payment_intent_id = p_payment_intent_id,
           stripe_amount_total_cents = p_amount_total_cents
     where id = p_order_id;
    insert into public.jobs (kind, payload, dedupe_key)
    values ('stripe.refund_full', jsonb_build_object('order_id', p_order_id, 'payment_intent', p_payment_intent_id),
            'auto-refund:' || p_order_id),
           ('admin.alert', jsonb_build_object('order_id', p_order_id, 'reason', 'paid_without_capacity'),
            'alert-soldout:' || p_order_id),
           ('email.order_refunded_soldout', jsonb_build_object('order_id', p_order_id),
            'email-soldout:' || p_order_id)
    on conflict (dedupe_key) do nothing;
    insert into public.audit_log (actor_type, action, entity_type, entity_id, data)
    values ('system', 'order.paid_without_capacity', 'order', p_order_id::text,
            jsonb_build_object('previous_status', v_order.status));
    return 'needs_refund';
  end if;

  -- Pagamento tardivo: le prenotazioni del prezzo membro si erano liberate con l'ordine e si riprendono. Se nel
  -- frattempo il membro ha già un biglietto a prezzo membro dello stesso tipo, i soldi sono già incassati: il
  -- biglietto si emette lo stesso e l'admin riceve un avviso (come per i codici sconto oltre il limite).
  if v_late then
    for c in select id from public.member_price_claims
             where order_id = p_order_id and released_at is not null order by id loop
      begin
        update public.member_price_claims set released_at = null, release_reason = null where id = c.id;
      exception when unique_violation then
        insert into public.jobs (kind, payload, dedupe_key)
        values ('admin.alert', jsonb_build_object('order_id', p_order_id, 'reason', 'member_price_limit_late'),
                'alert-member-limit:' || p_order_id)
        on conflict (dedupe_key) do nothing;
      end;
    end loop;
  end if;

  -- Codice sconto: se l'utilizzo era stato restituito alla scadenza, si riconta (pagamento già incassato).
  if v_order.discount_code_id is not null and not v_order.discount_reserved then
    update public.discount_codes set redeemed_count = redeemed_count + 1 where id = v_order.discount_code_id;
  end if;

  update public.orders
     set status = 'paid',
         paid_at = now(),
         discount_reserved = (v_order.discount_code_id is not null),
         stripe_payment_intent_id = p_payment_intent_id,
         stripe_amount_total_cents = p_amount_total_cents
   where id = p_order_id;

  -- Un biglietto per unità, con il nominativo raccolto nel carrello. Il biglietto a prezzo membro è
  -- intestato all'acquirente anche come account (attendee_profile_id).
  for i in select * from public.order_items where order_id = p_order_id order by id loop
    for k in 1 .. i.quantity loop
      insert into public.tickets (event_id, order_id, order_item_id, seq, ticket_type_id, price_role,
                                  attendee_name, attendee_email, attendee_profile_id, qr_key_id, short_code)
      values (v_order.event_id, p_order_id, i.id, k, i.ticket_type_id, i.price_role,
              i.attendees -> (k - 1) ->> 'name', i.attendees -> (k - 1) ->> 'email',
              case when i.price_role = 'member' then v_order.profile_id end,
              p_qr_key_id, public.new_short_code())
      on conflict (order_item_id, seq) do nothing;
    end loop;
  end loop;

  insert into public.jobs (kind, payload, dedupe_key)
  values ('email.order_confirmed', jsonb_build_object('order_id', p_order_id), 'order-confirmed:' || p_order_id)
  on conflict (dedupe_key) do nothing;

  if p_amount_total_cents is distinct from v_order.total_cents then
    insert into public.jobs (kind, payload, dedupe_key)
    values ('admin.alert', jsonb_build_object('order_id', p_order_id, 'reason', 'amount_mismatch',
                                              'expected', v_order.total_cents, 'stripe', p_amount_total_cents),
            'alert-amount:' || p_order_id)
    on conflict (dedupe_key) do nothing;
  end if;

  if v_late then
    insert into public.jobs (kind, payload, dedupe_key)
    values ('admin.alert', jsonb_build_object('order_id', p_order_id, 'reason', 'late_payment_rebooked'),
            'alert-late:' || p_order_id)
    on conflict (dedupe_key) do nothing;
    insert into public.audit_log (actor_type, action, entity_type, entity_id, data)
    values ('system', 'order.late_payment_rebooked', 'order', p_order_id::text,
            jsonb_build_object('previous_status', v_order.status));
    return 'paid_late';
  end if;
  return 'paid';
end;
$$;

-- Ordini 'pending' con riserva scaduta, per il job (Node) che ogni minuto:
--   1. se stripe_checkout_session_id è null (sessione mai salvata) chiude l'ordine: se la sessione esisteva
--      davvero e viene pagata, mark_order_paid passa dal ramo del pagamento tardivo;
--   2. altrimenti legge la sessione da Stripe: 'complete' -> mark_order_paid; 'expired' -> close_unpaid_order;
--      'open' -> chiama expire e poi close_unpaid_order.
-- Niente rilascio dei posti senza la conferma di Stripe (il vecchio rilascio cieco da pg_cron è stato tolto).
create function public.stale_pending_orders(p_limit integer default 50)
returns table (order_id uuid, stripe_checkout_session_id text)
language sql stable security definer
set search_path = ''
as $$
  select o.id, o.stripe_checkout_session_id
  from public.orders o
  where o.status = 'pending'
    and exists (select 1 from public.capacity_holds h
                where h.order_id = o.id and h.status = 'active' and h.expires_at < now())
  order by o.checkout_expires_at
  limit p_limit;
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

-- Cambio nominativo gratuito (decisione di Giulio del 05/11/2026). La chiama il server dopo aver verificato che
-- chi chiede è l'acquirente. Scadenza e divieto per i biglietti a prezzo membro li controlla il trigger
-- tickets_attendee_rules (errori name_change_deadline_passed, member_ticket_not_transferable).
-- Il numero massimo di cambi (2) è ancora una proposta: non è applicato qui.
create function public.change_ticket_attendee(p_ticket_id uuid, p_name text, p_email text, p_actor_id uuid)
returns integer
language plpgsql security definer
set search_path = ''
as $$
declare
  v_ticket public.tickets%rowtype;
begin
  select * into v_ticket from public.tickets where id = p_ticket_id for update;
  if not found or v_ticket.status <> 'valid' then
    raise exception 'ticket_not_valid' using errcode = 'P0001';
  end if;
  if lower(p_email) = lower(v_ticket.attendee_email::text) then
    raise exception 'same_attendee' using errcode = 'P0001';
  end if;
  update public.tickets
     set attendee_name = p_name,
         attendee_email = p_email,
         attendee_profile_id = (select p.id from public.profiles p where lower(p.email::text) = lower(p_email)),
         qr_version = qr_version + 1,
         name_changes_count = name_changes_count + 1
   where id = p_ticket_id;
  insert into public.audit_log (actor_id, actor_type, action, entity_type, entity_id, data)
  values (p_actor_id, 'user', 'ticket.name_changed', 'ticket', p_ticket_id::text,
          jsonb_build_object('qr_version', v_ticket.qr_version + 1));
  insert into public.jobs (kind, payload, dedupe_key)
  values ('email.ticket_reissued', jsonb_build_object('ticket_id', p_ticket_id),
          'ticket-reissued:' || p_ticket_id || ':' || (v_ticket.qr_version + 1))
  on conflict (dedupe_key) do nothing;
  return v_ticket.qr_version + 1;
end;
$$;

-- Le funzioni che spostano posti, soldi o biglietti le esegue solo il server (service_role).
-- In Postgres EXECUTE è di PUBLIC per default: va tolto esplicitamente.
revoke execute on function public.new_short_code() from public;
revoke execute on function public.order_pool_needs(uuid) from public;
revoke execute on function public.reserve_order(uuid, timestamptz) from public;
revoke execute on function public.release_order_reservations(uuid, text) from public;
revoke execute on function public.close_unpaid_order(uuid, public.order_status, text) from public;
revoke execute on function public.mark_order_processing(uuid, text) from public;
revoke execute on function public.mark_order_paid(uuid, text, text, integer, text) from public;
revoke execute on function public.stale_pending_orders(integer) from public;
revoke execute on function public.book_session(uuid, uuid) from public;
revoke execute on function public.price_roles_for(uuid, timestamptz) from public;
revoke execute on function public.order_items_member_rules() from public;
revoke execute on function public.order_items_claim_member_price() from public;
revoke execute on function public.orders_release_member_claims() from public;
revoke execute on function public.tickets_attendee_rules() from public;
revoke execute on function public.tickets_release_member_claim() from public;
revoke execute on function public.change_ticket_attendee(uuid, text, text, uuid) from public;
grant execute on function public.change_ticket_attendee(uuid, text, text, uuid) to service_role;
grant execute on function public.reserve_order(uuid, timestamptz) to service_role;
grant execute on function public.close_unpaid_order(uuid, public.order_status, text) to service_role;
grant execute on function public.mark_order_processing(uuid, text) to service_role;
grant execute on function public.mark_order_paid(uuid, text, text, integer, text) to service_role;
grant execute on function public.stale_pending_orders(integer) to service_role;
grant execute on function public.book_session(uuid, uuid) to service_role;
grant execute on function public.price_roles_for(uuid, timestamptz) to service_role;
-- release_order_reservations, order_pool_needs e new_short_code sono di uso interno: nessun grant.

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
alter table public.discount_codes             enable row level security;
alter table public.discount_code_ticket_types enable row level security;
alter table public.orders                     enable row level security;
alter table public.order_items                enable row level security;
alter table public.member_price_claims        enable row level security;
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

-- I listini sono pubblici (si mostra anche il prezzo membro per invogliare a iscriversi).
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
-- Pool e codici sconto toccano capienza e incassi: li scrive il server (service_role) dopo aver verificato
-- sessione admin e MFA, con controlli e registro audit. L'admin li può leggere.
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
create policy member_price_claims_admin_read on public.member_price_claims for select to authenticated
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
revoke all on public.profiles, public.user_roles, public.memberships,
              public.orders, public.order_items, public.member_price_claims, public.capacity_holds, public.tickets,
              public.session_bookings, public.waitlist_entries, public.checkins, public.refunds,
              public.stripe_events, public.jobs, public.audit_log, public.rate_limits,
              public.discount_codes, public.discount_code_ticket_types, public.capacity_pools,
              public.ticket_type_pools
  from anon;

commit;
