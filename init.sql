-- =============================================================================
--  TimeDeo — Time-Banking Platform
--  init.sql  —  Complete MySQL/MariaDB dump (schema v2)
--  Target: XAMPP (MySQL 8.0+ / MariaDB 10.4+)
--
--  Contents:
--    1. Database + 14 tables in 3NF, with all PK / FK / UNIQUE / CHECK constraints
--    2. Manual performance INDEXes (with rationale comments)   <-- RUBRIC: INDEX
--    3. Reusable VIEW: vw_active_marketplace_listings          <-- RUBRIC: VIEW
--    4. Seed data aligned to the React front-end's categories & content
--
--  v2 adds: profile fields + User_Contacts, help requests (Listings of type
--  'Request') with Help_Offers, scheduled bookings with a two-sided completion
--  state machine (pending -> in_progress -> delivered -> completed), two-way
--  Reviews (reviewee_id), Contact_Reveals, and Credit_Packages/Credit_Purchases.
--
--  All DATETIME values are stored in UTC (the API sets the session time zone to
--  +00:00 on every connection; this file does the same before seeding).
--
--  Import (from a shell):   mysql -u root < init.sql
--  Import (phpMyAdmin):     Import tab -> choose this file -> Go
--  Demo login password for EVERY seeded user:  password123
-- =============================================================================

-- This file is UTF-8 (it contains "—", "¡", "৳"). Say so explicitly, or the
-- Windows mysql client imports it as cp850 and stores garbled text.
SET NAMES utf8mb4;

-- Clean slate so the file can be re-imported at will.
DROP DATABASE IF EXISTS timedeo;
CREATE DATABASE timedeo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE timedeo;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1. Users  (identity + credentials + public profile)
-- -----------------------------------------------------------------------------
CREATE TABLE Users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120)  NOT NULL,
  email         VARCHAR(180)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,               -- bcrypt from PHP password_hash()
  headline      VARCHAR(120)  NULL,                   -- e.g. "Product designer"
  location      VARCHAR(120)  NULL,
  bio           TEXT          NULL,
  join_date     DATE          NOT NULL DEFAULT (CURRENT_DATE),
  CONSTRAINT uq_users_email UNIQUE (email)            -- email is the login handle
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 2. User_Contacts  (how a member can be reached; one row per method)
--    Never exposed publicly: the API only returns these to the owner, or to a
--    member with a booking / help offer / contact reveal involving them.
-- -----------------------------------------------------------------------------
CREATE TABLE User_Contacts (
  user_id INT          NOT NULL,
  method  VARCHAR(20)  NOT NULL,
  value   VARCHAR(160) NOT NULL,
  PRIMARY KEY (user_id, method),                      -- at most one value per method
  CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_contact_method CHECK (method IN ('phone','whatsapp','telegram','email','facebook'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 3. Wallets  (one time-credit wallet per user; 1 credit = 1 hour of service)
--    available_balance = spendable now;  escrow_balance = locked in open bookings
-- -----------------------------------------------------------------------------
CREATE TABLE Wallets (
  wallet_id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT           NOT NULL,
  available_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  escrow_balance    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT uq_wallet_user  UNIQUE (user_id),        -- 1:1 with Users
  CONSTRAINT fk_wallet_user  FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_escrow_nonneg  CHECK (escrow_balance >= 0),   -- required by spec
  CONSTRAINT chk_balance_nonneg CHECK (available_balance >= 0) -- guards the escrow transaction
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 4. Categories  (top-level skill grouping; matches front-end CATEGORIES)
-- -----------------------------------------------------------------------------
CREATE TABLE Categories (
  category_id   INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(80) NOT NULL,
  CONSTRAINT uq_category_name UNIQUE (category_name)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 5. Skills  (each skill belongs to exactly one category; members can add new ones)
-- -----------------------------------------------------------------------------
CREATE TABLE Skills (
  skill_id    INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT          NOT NULL,
  skill_name  VARCHAR(120) NOT NULL,
  CONSTRAINT uq_skill_name    UNIQUE (skill_name),
  CONSTRAINT fk_skill_category FOREIGN KEY (category_id) REFERENCES Categories(category_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6. User_Skills  (M:N — which users possess which skills; composite PK)
-- -----------------------------------------------------------------------------
CREATE TABLE User_Skills (
  user_id  INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (user_id, skill_id),                    -- composite key, no surrogate
  CONSTRAINT fk_us_user  FOREIGN KEY (user_id)  REFERENCES Users(user_id)   ON DELETE CASCADE,
  CONSTRAINT fk_us_skill FOREIGN KEY (skill_id) REFERENCES Skills(skill_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 7. Listings  (marketplace posts)
--    'Offer'   — the author PROVIDES the skill; others book it (author = provider).
--    'Request' — the author NEEDS help ("Ask for help"); skilled members respond
--                through Help_Offers and the author accepts one (author = requester).
-- -----------------------------------------------------------------------------
CREATE TABLE Listings (
  listing_id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT           NOT NULL,             -- author of the listing
  skill_id        INT           NOT NULL,
  listing_type    VARCHAR(10)   NOT NULL,
  title           VARCHAR(160)  NOT NULL,
  description     TEXT,
  estimated_hours DECIMAL(5,2)  NOT NULL,
  delivery_mode   VARCHAR(10)   NOT NULL DEFAULT 'online',
  preferred_at    DATETIME      NULL,                 -- Requests: when the author would like help
  status          VARCHAR(20)   NOT NULL DEFAULT 'active',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_listing_user  FOREIGN KEY (user_id)  REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_listing_skill FOREIGN KEY (skill_id) REFERENCES Skills(skill_id),
  CONSTRAINT chk_listing_type    CHECK (listing_type IN ('Offer','Request')),
  CONSTRAINT chk_estimated_hours CHECK (estimated_hours > 0),
  CONSTRAINT chk_delivery_mode   CHECK (delivery_mode IN ('online','local')),
  -- 'filled' = a Request whose helper has been chosen (no longer open)
  CONSTRAINT chk_listing_status  CHECK (status IN ('active','inactive','filled'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 8. Help_Offers  (a skilled member volunteers for a Request listing)
--    The Request's author accepts ONE offer -> a Booking is created and the
--    author's hours are locked in escrow. Other pending offers are declined.
-- -----------------------------------------------------------------------------
CREATE TABLE Help_Offers (
  offer_id    INT AUTO_INCREMENT PRIMARY KEY,
  listing_id  INT          NOT NULL,
  helper_id   INT          NOT NULL,
  message     TEXT         NULL,
  proposed_at DATETIME     NOT NULL,                  -- when the helper can do it
  status      VARCHAR(12)  NOT NULL DEFAULT 'pending',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_offer_once     UNIQUE (listing_id, helper_id),   -- one offer per helper per request
  CONSTRAINT fk_offer_listing  FOREIGN KEY (listing_id) REFERENCES Listings(listing_id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_helper   FOREIGN KEY (helper_id)  REFERENCES Users(user_id)       ON DELETE CASCADE,
  CONSTRAINT chk_offer_status  CHECK (status IN ('pending','accepted','declined','withdrawn'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 9. Bookings  (a requester engages a provider; two-sided completion)
--
--    pending ──provider accepts──▶ in_progress ──provider delivers──▶ delivered
--       │                              │                                 │
--       ├─provider declines / requester cancels ─▶ cancelled (refund)    │
--                                      └─provider cancels─▶ cancelled    │
--    delivered ──requester confirms (or 72h pass)──▶ completed (escrow paid out)
--    delivered ──requester "not done yet"──▶ in_progress
-- -----------------------------------------------------------------------------
CREATE TABLE Bookings (
  booking_id     INT AUTO_INCREMENT PRIMARY KEY,
  listing_id     INT          NOT NULL,
  requester_id   INT          NOT NULL,               -- pays credits (spends hours)
  provider_id    INT          NOT NULL,               -- earns credits (gives hours)
  agreed_hours   DECIMAL(5,2) NOT NULL,
  scheduled_at   DATETIME     NOT NULL,               -- agreed session date & time (UTC)
  note           TEXT         NULL,                   -- requester's note to the provider
  booking_status VARCHAR(20)  NOT NULL DEFAULT 'pending',
  status_note    VARCHAR(280) NULL,                   -- latest decline / "not done yet" reason
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at    DATETIME     NULL,
  delivered_at   DATETIME     NULL,                   -- starts the auto-release clock
  completed_at   DATETIME     NULL,
  cancelled_at   DATETIME     NULL,
  cancelled_by   INT          NULL,
  CONSTRAINT fk_booking_listing   FOREIGN KEY (listing_id)   REFERENCES Listings(listing_id),
  CONSTRAINT fk_booking_requester FOREIGN KEY (requester_id) REFERENCES Users(user_id),
  CONSTRAINT fk_booking_provider  FOREIGN KEY (provider_id)  REFERENCES Users(user_id),
  CONSTRAINT fk_booking_cancelled FOREIGN KEY (cancelled_by) REFERENCES Users(user_id),
  CONSTRAINT chk_agreed_hours   CHECK (agreed_hours > 0),
  CONSTRAINT chk_not_self_booking CHECK (requester_id <> provider_id),
  CONSTRAINT chk_booking_status CHECK (booking_status IN ('pending','in_progress','delivered','completed','cancelled'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 10. Transactions  (immutable ledger row — one payout per completed booking)
-- -----------------------------------------------------------------------------
CREATE TABLE Transactions (
  transaction_id    INT AUTO_INCREMENT PRIMARY KEY,
  booking_id        INT          NOT NULL,
  sender_id         INT          NOT NULL,            -- requester (credits leave escrow)
  receiver_id       INT          NOT NULL,            -- provider  (credits land in balance)
  hours_transferred DECIMAL(5,2) NOT NULL,
  release_reason    VARCHAR(12)  NOT NULL DEFAULT 'confirmed',
  timestamp         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_txn_booking   UNIQUE (booking_id),    -- at most one payout per booking
  CONSTRAINT fk_txn_booking   FOREIGN KEY (booking_id)  REFERENCES Bookings(booking_id),
  CONSTRAINT fk_txn_sender    FOREIGN KEY (sender_id)   REFERENCES Users(user_id),
  CONSTRAINT fk_txn_receiver  FOREIGN KEY (receiver_id) REFERENCES Users(user_id),
  CONSTRAINT chk_hours_transferred CHECK (hours_transferred > 0),
  CONSTRAINT chk_release_reason CHECK (release_reason IN ('confirmed','auto'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 11. Reviews  (each participant may rate the OTHER once per booking)
--     reviewee_id is stored (not derived per query) so ratings can be
--     aggregated per member with a plain index seek.
-- -----------------------------------------------------------------------------
CREATE TABLE Reviews (
  review_id   INT AUTO_INCREMENT PRIMARY KEY,
  booking_id  INT  NOT NULL,
  reviewer_id INT  NOT NULL,
  reviewee_id INT  NOT NULL,
  rating      INT  NOT NULL,
  comment     TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_review_booking  FOREIGN KEY (booking_id)  REFERENCES Bookings(booking_id),
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES Users(user_id),
  CONSTRAINT fk_review_reviewee FOREIGN KEY (reviewee_id) REFERENCES Users(user_id),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT chk_review_not_self CHECK (reviewer_id <> reviewee_id),
  CONSTRAINT uq_review_once UNIQUE (booking_id, reviewer_id)        -- no double-reviewing
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 12. Contact_Reveals  (audit of "show me their contact" — intent to trade)
--     A reveal is MUTUAL: once A reveals B's contact for a listing, B can see
--     A's contact too, so both sides can reach each other before confirming.
-- -----------------------------------------------------------------------------
CREATE TABLE Contact_Reveals (
  reveal_id  INT AUTO_INCREMENT PRIMARY KEY,
  viewer_id  INT      NOT NULL,
  target_id  INT      NOT NULL,
  listing_id INT      NOT NULL,                       -- the post that prompted the reveal
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_reveal_once     UNIQUE (viewer_id, target_id, listing_id),
  CONSTRAINT fk_reveal_viewer   FOREIGN KEY (viewer_id)  REFERENCES Users(user_id)       ON DELETE CASCADE,
  CONSTRAINT fk_reveal_target   FOREIGN KEY (target_id)  REFERENCES Users(user_id)       ON DELETE CASCADE,
  CONSTRAINT fk_reveal_listing  FOREIGN KEY (listing_id) REFERENCES Listings(listing_id) ON DELETE CASCADE,
  CONSTRAINT chk_reveal_not_self CHECK (viewer_id <> target_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 13. Credit_Packages  (server-side price list — the client never sets a price)
-- -----------------------------------------------------------------------------
CREATE TABLE Credit_Packages (
  package_id INT AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(40)   NOT NULL,
  credits    DECIMAL(6,2)  NOT NULL,
  price_bdt  DECIMAL(10,2) NOT NULL,
  is_active  TINYINT(1)    NOT NULL DEFAULT 1,
  CONSTRAINT chk_pkg_credits CHECK (credits > 0),
  CONSTRAINT chk_pkg_price   CHECK (price_bdt > 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 14. Credit_Purchases  (demo top-ups; credits + price are copied from the
--     package at purchase time so later price changes never rewrite history)
-- -----------------------------------------------------------------------------
CREATE TABLE Credit_Purchases (
  purchase_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT           NOT NULL,
  package_id     INT           NOT NULL,
  credits        DECIMAL(6,2)  NOT NULL,
  amount_bdt     DECIMAL(10,2) NOT NULL,
  method         VARCHAR(20)   NOT NULL DEFAULT 'bkash_demo',
  payer_account  VARCHAR(20)   NOT NULL,              -- masked, e.g. 017******78
  trx_id         VARCHAR(20)   NOT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_purchase_trx  UNIQUE (trx_id),
  CONSTRAINT fk_purchase_user FOREIGN KEY (user_id)    REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_pkg  FOREIGN KEY (package_id) REFERENCES Credit_Packages(package_id),
  CONSTRAINT chk_purchase_method CHECK (method IN ('bkash_demo'))
) ENGINE=InnoDB;


-- =============================================================================
--  RUBRIC: INDEX  —  manual indexes to optimize hot-path queries
-- =============================================================================

-- The dashboard and Bookings page constantly ask "give me THIS user's bookings
-- filtered by status" (e.g. active = pending/in_progress/delivered). Without an
-- index that scans the whole Bookings table. A composite index on
-- (provider_id, booking_status) lets MySQL seek straight to a provider's rows AND
-- filter by status from the index, turning the "incoming jobs" query into an
-- index range scan.
CREATE INDEX idx_bookings_provider_status ON Bookings (provider_id, booking_status);

-- Same access pattern from the requester side ("my outgoing bookings by status").
CREATE INDEX idx_bookings_requester_status ON Bookings (requester_id, booking_status);

-- The auto-release sweep looks for delivered bookings older than the window;
-- (booking_status, delivered_at) makes that a short range scan.
CREATE INDEX idx_bookings_status_delivered ON Bookings (booking_status, delivered_at);

-- The marketplace filters on (listing_type, status) on every page load
-- ("active Offers" for Find help, "active Requests" for Help wanted).
CREATE INDEX idx_listings_type_status ON Listings (listing_type, status);

-- Ratings are aggregated per member (profile, marketplace, top providers).
CREATE INDEX idx_reviews_reviewee ON Reviews (reviewee_id);

-- Reviews are also joined per booking (listing ratings); index the FK.
CREATE INDEX idx_reviews_booking ON Reviews (booking_id);

-- A request's author loads its offers filtered by status.
CREATE INDEX idx_help_offers_listing_status ON Help_Offers (listing_id, status);

-- "Who revealed my contact?" inbox.
CREATE INDEX idx_reveals_target ON Contact_Reveals (target_id, created_at);


-- =============================================================================
--  RUBRIC: VIEW  —  vw_active_marketplace_listings
--  A reusable storefront projection that pre-joins Listings -> Users -> Skills
--  -> Categories and LEFT-joins ratings, so the API can query one clean object
--  instead of re-writing this 4-table join everywhere. Only reviews the
--  PROVIDER received count towards a listing's rating.
-- =============================================================================
CREATE VIEW vw_active_marketplace_listings AS
SELECT
    l.listing_id,
    l.title,
    l.description,
    l.listing_type,
    l.estimated_hours,
    l.delivery_mode,
    l.status,
    l.created_at,
    u.user_id      AS provider_id,
    u.full_name    AS provider_name,
    s.skill_id,
    s.skill_name,
    c.category_id,
    c.category_name,
    COUNT(DISTINCT r.review_id)      AS review_count,
    ROUND(AVG(r.rating), 2)          AS avg_rating
FROM Listings   l
INNER JOIN Users      u ON u.user_id     = l.user_id       -- listing author (provider)
INNER JOIN Skills     s ON s.skill_id    = l.skill_id
INNER JOIN Categories c ON c.category_id = s.category_id
LEFT  JOIN Bookings   b ON b.listing_id  = l.listing_id    -- may have no bookings yet
LEFT  JOIN Reviews    r ON r.booking_id  = b.booking_id    -- may have no reviews yet
                       AND r.reviewee_id = b.provider_id   -- only the provider's ratings
WHERE l.status = 'active' AND l.listing_type = 'Offer'
GROUP BY
    l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
    l.delivery_mode, l.status, l.created_at, u.user_id, u.full_name,
    s.skill_id, s.skill_name, c.category_id, c.category_name;


-- =============================================================================
--  SEED DATA
--  Every user's password is "password123" (bcrypt hash below, generated with
--  PHP password_hash()). Times are relative to NOW() so the demo stays fresh.
-- =============================================================================
SET @pw := '$2y$10$jvbppnzqERZ6lwxDQqry1e5NBFDwrrExUPLKmsCvsMQ8eqpRN6maO';

-- ---- Users ----  (user_id 1..7; id 1 = "Avery", the demo login)
INSERT INTO Users (full_name, email, password_hash, headline, location, bio, join_date) VALUES
  ('Avery Okafor',    'avery@timedeo.test', @pw, 'Product designer', 'Dhanmondi, Dhaka',
   'I design calm, useful interfaces. Happy to critique your app or landing page — I trade for language practice and home fixes.', '2023-02-01'),
  ('Mara Devlin',     'mara@timedeo.test',  @pw, 'Brand & web designer', 'Gulshan, Dhaka',
   'Ten years of brand systems and small marketing sites. I love turning a messy idea into a clear identity.', '2023-03-12'),
  ('Idris Kwon',      'idris@timedeo.test', @pw, 'Frontend engineer', 'Banani, Dhaka',
   'React and performance nerd. If your app feels slow, I will find out why.', '2023-04-08'),
  ('Lucia Marin',     'lucia@timedeo.test', @pw, 'Language teacher', 'Uttara, Dhaka',
   'Native Spanish speaker, patient teacher. Conversation first, grammar second.', '2023-05-19'),
  ('Tom Bishop',      'tom@timedeo.test',   @pw, 'Handyman', 'Mirpur, Dhaka',
   'Plumbing, furniture, small repairs. Bring the tools question, I bring the tools.', '2023-06-02'),
  ('Priya Nair',      'priya@timedeo.test', @pw, 'Yoga instructor', 'Bashundhara, Dhaka',
   'Certified vinyasa teacher. Gentle, breath-led sessions for every body.', '2023-07-15'),
  ('Grace Lindqvist', 'grace@timedeo.test', @pw, 'Math tutor', 'Mohammadpur, Dhaka',
   'PhD student in applied math. Calculus does not have to be scary.', '2023-08-21');

-- ---- User_Contacts ----  (revealed only to members who want to trade)
INSERT INTO User_Contacts (user_id, method, value) VALUES
  (1, 'phone', '+8801711000001'), (1, 'whatsapp', '+8801711000001'),
  (2, 'phone', '+8801711000002'), (2, 'email', 'mara.studio@example.com'),
  (3, 'phone', '+8801711000003'), (3, 'telegram', '@idris_dev'),
  (4, 'phone', '+8801711000004'), (4, 'whatsapp', '+8801711000004'),
  (5, 'phone', '+8801711000005'),
  (6, 'phone', '+8801711000006'), (6, 'facebook', 'facebook.com/priya.flow'),
  (7, 'phone', '+8801711000007'), (7, 'email', 'grace.tutor@example.com');

-- ---- Wallets ----  escrow_balance = the requester's open (pending / in_progress /
--      delivered) bookings below — the books must balance.
INSERT INTO Wallets (user_id, available_balance, escrow_balance) VALUES
  (1, 12.50, 4.50),   -- Avery: b1 2.0 + b2 1.5 + b11 1.0
  (2, 21.00, 1.50),   -- Mara:  b13 1.5
  (3,  8.00, 0.00),   -- Idris
  (4, 15.00, 0.00),   -- Lucia
  (5,  6.50, 0.00),   -- Tom
  (6, 18.00, 1.50),   -- Priya: b3 1.5
  (7, 24.00, 1.50);   -- Grace: b12 1.5

-- ---- Categories ----  (mirrors client/src/data/categories.js)
INSERT INTO Categories (category_name) VALUES
  ('Design'),          -- 1
  ('Development'),     -- 2
  ('Tutoring'),        -- 3
  ('Home & Repair'),   -- 4
  ('Wellness'),        -- 5
  ('Writing'),         -- 6
  ('Music'),           -- 7
  ('Languages'),       -- 8
  ('Photography'),     -- 9
  ('Business');        -- 10

-- ---- Skills ----  (skill_id in insertion order; members can add more)
INSERT INTO Skills (category_id, skill_name) VALUES
  (1,  'UI/UX Design'),          -- 1
  (1,  'Brand Identity'),        -- 2
  (1,  'Design Systems'),        -- 3
  (2,  'React Development'),     -- 4
  (2,  'Web Development'),       -- 5
  (3,  'Calculus Tutoring'),     -- 6
  (4,  'Plumbing'),              -- 7
  (4,  'Furniture Assembly'),    -- 8
  (5,  'Yoga'),                  -- 9
  (6,  'Copy Editing'),          -- 10
  (7,  'Guitar'),                -- 11
  (8,  'Spanish'),               -- 12
  (9,  'Product Photography'),   -- 13
  (10, 'Go-to-Market Strategy'), -- 14
  (8,  'English Conversation'),  -- 15
  (8,  'Arabic'),                -- 16
  (3,  'Physics Tutoring');      -- 17

-- ---- User_Skills ----  (who can do what)
INSERT INTO User_Skills (user_id, skill_id) VALUES
  (1, 1), (1, 3),               -- Avery: UI/UX, Design Systems
  (2, 2), (2, 3), (2, 5),       -- Mara:  Brand, Design Systems, Web Dev
  (3, 4), (3, 5),               -- Idris: React, Web Dev
  (4, 12), (4, 15),             -- Lucia: Spanish, English Conversation
  (5, 7), (5, 8),               -- Tom:   Plumbing, Furniture Assembly
  (6, 9),                       -- Priya: Yoga
  (7, 6), (7, 17);              -- Grace: Calculus, Physics

-- ---- Listings ----
--   Offers 1..11 (11 is inactive, to demo the status filter).
--   Design has 3 active Offers, Development 2, Home & Repair 2 (HAVING demo).
INSERT INTO Listings (user_id, skill_id, listing_type, title, description, estimated_hours, delivery_mode, status, created_at) VALUES
  (2, 2,  'Offer', 'Brand identity & logo direction',   'Define voice, type, and a first logo direction you can run with.', 3.00, 'online', 'active', NOW() - INTERVAL 60 DAY),  -- 1
  (2, 3,  'Offer', 'Figma design system setup',         'Tokens, core components, and a variables setup your team can build on.', 4.00, 'online', 'active', NOW() - INTERVAL 58 DAY), -- 2
  (1, 1,  'Offer', 'Product design critique',           'A focused critique of your product UI with actionable notes.', 1.50, 'online', 'active', NOW() - INTERVAL 55 DAY),      -- 3 (Avery)
  (3, 4,  'Offer', 'React performance audit',           'Screen-share audit: render bottlenecks, bundle weight, prioritized fixes.', 2.00, 'online', 'active', NOW() - INTERVAL 50 DAY), -- 4
  (2, 5,  'Offer', 'Website design & build sprint',     'Soup-to-nuts: design, build, and ship a small marketing site.', 16.00, 'online', 'active', NOW() - INTERVAL 45 DAY),    -- 5
  (4, 12, 'Offer', 'Conversational Spanish, 1-on-1',    'Natural, low-pressure speaking practice tuned to your level.', 1.00, 'online', 'active', NOW() - INTERVAL 40 DAY),      -- 6
  (5, 7,  'Offer', 'Leaky faucet & sink repair',        'Diagnose and fix drips, slow drains, and worn cartridges.', 1.50, 'local', 'active', NOW() - INTERVAL 38 DAY),          -- 7
  (5, 8,  'Offer', 'Flat-pack furniture assembly',      'Wardrobes, shelves, desks — assembled, leveled, and anchored safely.', 2.00, 'local', 'active', NOW() - INTERVAL 35 DAY), -- 8
  (6, 9,  'Offer', 'Vinyasa flow, private session',     'A private flow shaped to your body — mobility, breath, calm.', 1.00, 'local', 'active', NOW() - INTERVAL 30 DAY),        -- 9
  (7, 6,  'Offer', 'Calculus tutoring (AP / college)',  'Limits, derivatives, integrals — untangled with worked examples.', 1.00, 'online', 'active', NOW() - INTERVAL 28 DAY), -- 10
  (2, 2,  'Offer', 'Logo refresh (archived sample)',    'An older listing kept inactive to demo the status filter.', 2.00, 'online', 'inactive', NOW() - INTERVAL 90 DAY);       -- 11

--   Requests 12..15 ("Ask for help") — discoverable by members with matching skills.
INSERT INTO Listings (user_id, skill_id, listing_type, title, description, estimated_hours, delivery_mode, preferred_at, status, created_at) VALUES
  (1, 8,  'Request', 'Help assembling a bookshelf',     'IKEA-style bookshelf, about 30 parts. I have a screwdriver set but not much patience.', 2.00, 'local', NOW() + INTERVAL 4 DAY, 'active', NOW() - INTERVAL 2 DAY),        -- 12 Avery needs Tom-like skills
  (6, 1,  'Request', 'Feedback on my studio website',   'I built a site for my yoga studio and the booking page confuses people. Looking for a UX eye.', 1.50, 'online', NOW() + INTERVAL 5 DAY, 'active', NOW() - INTERVAL 1 DAY), -- 13 matches Avery's UI/UX skill
  (7, 12, 'Request', 'Spanish practice before a trip',  'Travelling to Madrid next month — want to practise ordering food and asking directions.', 1.00, 'online', NOW() + INTERVAL 6 DAY, 'active', NOW() - INTERVAL 3 DAY), -- 14
  (5, 4,  'Request', 'Fix validation in my React form', 'My booking form lets people submit empty phone numbers. Probably a 30-minute fix for someone who knows React.', 1.00, 'online', NULL, 'active', NOW() - INTERVAL 12 HOUR); -- 15

-- ---- Help_Offers ----  (helpers volunteering for the open requests)
INSERT INTO Help_Offers (listing_id, helper_id, message, proposed_at, status, created_at) VALUES
  (12, 5, 'I assemble these every week — I can bring a drill and wall anchors too.', NOW() + INTERVAL 4 DAY + INTERVAL 2 HOUR, 'pending', NOW() - INTERVAL 1 DAY),  -- Tom -> Avery's bookshelf
  (14, 4, '¡Claro! We can role-play a café and a metro station.', NOW() + INTERVAL 5 DAY, 'pending', NOW() - INTERVAL 2 DAY),                                        -- Lucia -> Grace
  (15, 3, 'Happy to pair on it — send me the repo link beforehand.', NOW() + INTERVAL 1 DAY, 'pending', NOW() - INTERVAL 6 HOUR);                                     -- Idris -> Tom

-- ---- Bookings ----
--   ACTIVE:
--     b1  Avery -> Idris  React audit        in_progress (Avery waits for delivery)
--     b2  Avery -> Tom    faucet             pending     (Tom must accept)
--     b3  Priya -> Avery  design critique    in_progress (Avery can mark delivered)
--     b11 Avery -> Lucia  Spanish            delivered   (Avery should confirm & release)
--     b12 Grace -> Avery  design critique    pending     (Avery can accept / decline)
--     b13 Mara  -> Avery  design critique    delivered 60h ago (auto-releases in ~12h)
--   COMPLETED b4..b10 (each has a Transactions row; most have Reviews).
INSERT INTO Bookings (listing_id, requester_id, provider_id, agreed_hours, scheduled_at, note, booking_status, created_at, accepted_at, delivered_at, completed_at) VALUES
  (4,  1, 3, 2.00, NOW() + INTERVAL 1 DAY,  'Our dashboard takes 4s to render — the Orders page is the worst.', 'in_progress', NOW() - INTERVAL 3 DAY,  NOW() - INTERVAL 2 DAY, NULL, NULL),                    -- 1
  (7,  1, 5, 1.50, NOW() + INTERVAL 2 DAY,  'Kitchen tap drips constantly.', 'pending',     NOW() - INTERVAL 5 HOUR, NULL, NULL, NULL),                                                        -- 2
  (3,  6, 1, 1.50, NOW() + INTERVAL 1 DAY,  'Mostly the class-booking flow on mobile.', 'in_progress', NOW() - INTERVAL 2 DAY,  NOW() - INTERVAL 1 DAY, NULL, NULL),                        -- 3
  (6,  1, 4, 1.00, NOW() - INTERVAL 30 DAY, NULL, 'completed', NOW() - INTERVAL 33 DAY, NOW() - INTERVAL 32 DAY, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 30 DAY),                         -- 4
  (3,  6, 1, 1.50, NOW() - INTERVAL 25 DAY, NULL, 'completed', NOW() - INTERVAL 27 DAY, NOW() - INTERVAL 26 DAY, NOW() - INTERVAL 25 DAY, NOW() - INTERVAL 24 DAY),                         -- 5
  (1,  7, 2, 3.00, NOW() - INTERVAL 21 DAY, NULL, 'completed', NOW() - INTERVAL 24 DAY, NOW() - INTERVAL 23 DAY, NOW() - INTERVAL 21 DAY, NOW() - INTERVAL 21 DAY),                         -- 6
  (4,  5, 3, 2.00, NOW() - INTERVAL 18 DAY, NULL, 'completed', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 19 DAY, NOW() - INTERVAL 18 DAY, NOW() - INTERVAL 17 DAY),                         -- 7
  (10, 4, 7, 1.00, NOW() - INTERVAL 14 DAY, NULL, 'completed', NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 14 DAY),                         -- 8
  (9,  2, 6, 1.00, NOW() - INTERVAL 10 DAY, NULL, 'completed', NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),                         -- 9
  (2,  3, 2, 4.00, NOW() - INTERVAL 7 DAY,  NULL, 'completed', NOW() - INTERVAL 9 DAY,  NOW() - INTERVAL 8 DAY,  NOW() - INTERVAL 7 DAY,  NOW() - INTERVAL 6 DAY),                          -- 10
  (6,  1, 4, 1.00, NOW() - INTERVAL 1 DAY,  'Travel phrases, please!', 'delivered', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 20 HOUR, NULL),                      -- 11
  (3,  7, 1, 1.50, NOW() + INTERVAL 3 DAY,  'Tutoring site landing page — does it convert?', 'pending', NOW() - INTERVAL 2 HOUR, NULL, NULL, NULL),                                       -- 12
  (3,  2, 1, 1.50, NOW() - INTERVAL 3 DAY,  'Portfolio case-study pages.', 'delivered', NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 60 HOUR, NULL);                  -- 13

-- ---- Transactions ----  (one immutable ledger row per COMPLETED booking above)
INSERT INTO Transactions (booking_id, sender_id, receiver_id, hours_transferred, release_reason, timestamp) VALUES
  (4,  1, 4, 1.00, 'confirmed', NOW() - INTERVAL 30 DAY),   -- Avery paid Lucia
  (5,  6, 1, 1.50, 'confirmed', NOW() - INTERVAL 24 DAY),   -- Priya paid Avery
  (6,  7, 2, 3.00, 'confirmed', NOW() - INTERVAL 21 DAY),   -- Grace paid Mara
  (7,  5, 3, 2.00, 'auto',      NOW() - INTERVAL 17 DAY),   -- Tom's hours auto-released to Idris
  (8,  4, 7, 1.00, 'confirmed', NOW() - INTERVAL 14 DAY),   -- Lucia paid Grace
  (9,  2, 6, 1.00, 'confirmed', NOW() - INTERVAL 10 DAY),   -- Mara paid Priya
  (10, 3, 2, 4.00, 'confirmed', NOW() - INTERVAL 6 DAY);    -- Idris paid Mara

-- ---- Reviews ----  (both directions; ratings chosen so the platform average of
--   provider ratings lands ~4.71 and the correlated-subquery demo returns a clear
--   subset of "above average" providers)
INSERT INTO Reviews (booking_id, reviewer_id, reviewee_id, rating, comment, created_at) VALUES
  (4,  1, 4, 5, 'Natural, encouraging practice — left with real vocabulary.',        NOW() - INTERVAL 30 DAY),  -- Lucia (provider) gets 5
  (5,  6, 1, 5, 'Reframed our onboarding in twenty minutes flat. Rebooked.',         NOW() - INTERVAL 24 DAY),  -- Avery (provider) gets 5
  (5,  1, 6, 5, 'Clear brief and great questions. A pleasure to help.',              NOW() - INTERVAL 24 DAY),  -- Priya (client) gets 5
  (6,  7, 2, 4, 'Strong systems eye; wanted a touch more written documentation.',    NOW() - INTERVAL 21 DAY),  -- Mara gets 4
  (7,  5, 3, 5, 'Found the exact renders killing our dashboard. Superb.',            NOW() - INTERVAL 17 DAY),  -- Idris gets 5
  (7,  3, 5, 4, 'Friendly and on time; shared the repo a bit late.',                 NOW() - INTERVAL 17 DAY),  -- Tom (client) gets 4
  (8,  4, 7, 5, 'Made calculus finally click. Patient and clear.',                   NOW() - INTERVAL 14 DAY),  -- Grace gets 5
  (9,  2, 6, 4, 'Lovely, well-paced flow. Felt great afterward.',                    NOW() - INTERVAL 10 DAY),  -- Priya gets 4
  (10, 3, 2, 5, 'Tokens and components saved my team weeks of work.',               NOW() - INTERVAL 6 DAY);   -- Mara gets 5 (avg 4.5)

-- ---- Contact_Reveals ----  (Grace looked up Avery's contact before booking b12)
INSERT INTO Contact_Reveals (viewer_id, target_id, listing_id, created_at) VALUES
  (7, 1, 3, NOW() - INTERVAL 3 HOUR);

-- ---- Credit_Packages ----  (৳100 per hour with bulk discounts)
INSERT INTO Credit_Packages (label, credits, price_bdt) VALUES
  ('Starter',    1.00,  100.00),
  ('Popular',    5.00,  475.00),
  ('Value',     10.00,  900.00),
  ('Community', 25.00, 2000.00);

-- =============================================================================
--  End of init.sql
-- =============================================================================
