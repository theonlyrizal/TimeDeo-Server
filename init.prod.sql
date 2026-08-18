-- =============================================================================
--  TimeDeo — Time-Banking Platform
--  init.prod.sql  —  PRODUCTION bootstrap (run ONCE against a managed MySQL)
--  Target: MySQL 8.0+ / MariaDB 10.4+  (Railway, PlanetScale, RDS, …)
--
--  Differs from init.sql: it has NO "DROP DATABASE / CREATE DATABASE / USE"
--  (managed hosts pre-create your database and forbid those). It builds the
--  schema in whatever database your connection already selected. With no DROP,
--  re-running this file will error on the existing tables — that is intentional.
--  Evolve the schema with migrations from here, never by re-running this file.
--
--  Contents:
--    1. Database + 9 tables in 3NF, with all PK / FK / UNIQUE / CHECK constraints
--    2. Manual performance INDEXes (with rationale comments)   <-- RUBRIC: INDEX
--    3. Reusable VIEW: vw_active_marketplace_listings          <-- RUBRIC: VIEW
--    4. Seed data aligned to the React front-end's categories & mock content
--
--  Import (once):  mysql -h <host> -P <port> -u <user> -p<password> <database> \
--                        --default-character-set=utf8mb4 < init.prod.sql
--  Demo login password for EVERY seeded user:  password123
--  ^ this seed is DEMO data (fake users/bookings). Remove or replace it once
--    real auth + registration are in place. See PRODUCTION.md.
-- =============================================================================

-- Force the client connection charset so multibyte text (em-dashes, Bengali,
-- accented names) imports correctly regardless of the client's OS default.
SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Users  (identity + credentials)
-- -----------------------------------------------------------------------------
CREATE TABLE Users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120)  NOT NULL,
  email         VARCHAR(180)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,               -- bcrypt from PHP password_hash()
  join_date     DATE          NOT NULL DEFAULT (CURRENT_DATE),
  CONSTRAINT uq_users_email UNIQUE (email)            -- email is the login handle
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 2. Wallets  (one time-credit wallet per user; 1 credit = 1 hour of service)
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
-- 3. Categories  (top-level skill grouping; matches front-end CATEGORIES)
-- -----------------------------------------------------------------------------
CREATE TABLE Categories (
  category_id   INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(80) NOT NULL,
  CONSTRAINT uq_category_name UNIQUE (category_name)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 4. Skills  (each skill belongs to exactly one category)
-- -----------------------------------------------------------------------------
CREATE TABLE Skills (
  skill_id    INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT          NOT NULL,
  skill_name  VARCHAR(120) NOT NULL,
  CONSTRAINT uq_skill_name    UNIQUE (skill_name),
  CONSTRAINT fk_skill_category FOREIGN KEY (category_id) REFERENCES Categories(category_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 5. User_Skills  (M:N — which users possess which skills; composite PK)
-- -----------------------------------------------------------------------------
CREATE TABLE User_Skills (
  user_id  INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (user_id, skill_id),                    -- composite key, no surrogate
  CONSTRAINT fk_us_user  FOREIGN KEY (user_id)  REFERENCES Users(user_id)   ON DELETE CASCADE,
  CONSTRAINT fk_us_skill FOREIGN KEY (skill_id) REFERENCES Skills(skill_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6. Listings  (marketplace posts — an Offer of a skill or a Request for one)
-- -----------------------------------------------------------------------------
CREATE TABLE Listings (
  listing_id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT           NOT NULL,             -- author of the listing
  skill_id        INT           NOT NULL,
  listing_type    VARCHAR(10)   NOT NULL,
  title           VARCHAR(160)  NOT NULL,
  description     TEXT,
  estimated_hours DECIMAL(5,2)  NOT NULL,
  status          VARCHAR(20)   NOT NULL DEFAULT 'active',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- additive: for ORDER BY
  CONSTRAINT fk_listing_user  FOREIGN KEY (user_id)  REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_listing_skill FOREIGN KEY (skill_id) REFERENCES Skills(skill_id),
  CONSTRAINT chk_listing_type    CHECK (listing_type IN ('Offer','Request')),
  CONSTRAINT chk_estimated_hours CHECK (estimated_hours > 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 7. Bookings  (a requester engages a provider for a listing's service)
-- -----------------------------------------------------------------------------
CREATE TABLE Bookings (
  booking_id     INT AUTO_INCREMENT PRIMARY KEY,
  listing_id     INT          NOT NULL,
  requester_id   INT          NOT NULL,               -- pays credits (spends hours)
  provider_id    INT          NOT NULL,               -- earns credits (gives hours)
  agreed_hours   DECIMAL(5,2) NOT NULL,
  booking_status VARCHAR(20)  NOT NULL DEFAULT 'pending',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- additive: for ORDER BY
  CONSTRAINT fk_booking_listing   FOREIGN KEY (listing_id)   REFERENCES Listings(listing_id),
  CONSTRAINT fk_booking_requester FOREIGN KEY (requester_id) REFERENCES Users(user_id),
  CONSTRAINT fk_booking_provider  FOREIGN KEY (provider_id)  REFERENCES Users(user_id),
  CONSTRAINT chk_agreed_hours   CHECK (agreed_hours > 0),
  CONSTRAINT chk_booking_status CHECK (booking_status IN ('pending','in_progress','completed','cancelled'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 8. Transactions  (immutable ledger row — one per completed booking)
-- -----------------------------------------------------------------------------
CREATE TABLE Transactions (
  transaction_id    INT AUTO_INCREMENT PRIMARY KEY,
  booking_id        INT          NOT NULL,
  sender_id         INT          NOT NULL,            -- requester (credits leave escrow)
  receiver_id       INT          NOT NULL,            -- provider  (credits land in balance)
  hours_transferred DECIMAL(5,2) NOT NULL,
  timestamp         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_txn_booking   UNIQUE (booking_id),    -- at most one payout per booking
  CONSTRAINT fk_txn_booking   FOREIGN KEY (booking_id)  REFERENCES Bookings(booking_id),
  CONSTRAINT fk_txn_sender    FOREIGN KEY (sender_id)   REFERENCES Users(user_id),
  CONSTRAINT fk_txn_receiver  FOREIGN KEY (receiver_id) REFERENCES Users(user_id),
  CONSTRAINT chk_hours_transferred CHECK (hours_transferred > 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 9. Reviews  (a booking participant rates the exchange; one review per person)
-- -----------------------------------------------------------------------------
CREATE TABLE Reviews (
  review_id   INT AUTO_INCREMENT PRIMARY KEY,
  booking_id  INT  NOT NULL,
  reviewer_id INT  NOT NULL,
  rating      INT  NOT NULL,
  comment     TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,          -- additive: for ORDER BY
  CONSTRAINT fk_review_booking  FOREIGN KEY (booking_id)  REFERENCES Bookings(booking_id),
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES Users(user_id),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT uq_review_once UNIQUE (booking_id, reviewer_id)        -- no double-reviewing
) ENGINE=InnoDB;


-- =============================================================================
--  RUBRIC: INDEX  —  manual indexes to optimize hot-path queries
-- =============================================================================

-- The dashboard and Bookings page constantly ask "give me THIS user's bookings
-- filtered by status" (e.g. active = pending/in_progress). Without an index that
-- scans the whole Bookings table. A composite index on (provider_id, booking_status)
-- lets MySQL seek straight to a provider's rows AND filter by status from the index,
-- turning the dashboard "incoming jobs" query into an index range scan.
CREATE INDEX idx_bookings_provider_status ON Bookings (provider_id, booking_status);

-- Same access pattern from the requester side ("my outgoing bookings by status").
CREATE INDEX idx_bookings_requester_status ON Bookings (requester_id, booking_status);

-- The marketplace/view filters on status='active' on nearly every page load;
-- indexing status avoids a full-table scan of Listings for the storefront.
CREATE INDEX idx_listings_status ON Listings (status);

-- Reviews are aggregated per booking (AVG rating on listings); index the FK we join on.
CREATE INDEX idx_reviews_booking ON Reviews (booking_id);


-- =============================================================================
--  RUBRIC: VIEW  —  vw_active_marketplace_listings
--  A reusable storefront projection that pre-joins Listings -> Users -> Skills
--  -> Categories and LEFT-joins ratings, so the API can query one clean object
--  instead of re-writing this 4-table join everywhere.
-- =============================================================================
CREATE VIEW vw_active_marketplace_listings AS
SELECT
    l.listing_id,
    l.title,
    l.description,
    l.listing_type,
    l.estimated_hours,
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
WHERE l.status = 'active'
GROUP BY
    l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
    l.status, l.created_at, u.user_id, u.full_name,
    s.skill_id, s.skill_name, c.category_id, c.category_name;


-- =============================================================================
--  SEED DATA
--  Every user's password is "password123" (bcrypt hash below, generated with
--  PHP password_hash()). Shared via a session variable for readability.
-- =============================================================================
SET @pw := '$2y$10$jvbppnzqERZ6lwxDQqry1e5NBFDwrrExUPLKmsCvsMQ8eqpRN6maO';

-- ---- Users ----  (user_id 1..7; id 1 = "Avery", the front-end's current user)
INSERT INTO Users (full_name, email, password_hash, join_date) VALUES
  ('Avery Okafor',     'avery@timedeo.test', @pw, '2023-02-01'),
  ('Mara Devlin',      'mara@timedeo.test',  @pw, '2023-03-12'),
  ('Idris Kwon',       'idris@timedeo.test', @pw, '2023-04-08'),
  ('Lucia Marin',      'lucia@timedeo.test', @pw, '2023-05-19'),
  ('Tom Bishop',       'tom@timedeo.test',   @pw, '2023-06-02'),
  ('Priya Nair',       'priya@timedeo.test', @pw, '2023-07-15'),
  ('Grace Lindqvist',  'grace@timedeo.test', @pw, '2023-08-21');

-- ---- Wallets ----  (Avery's escrow_balance = 3.50 = the two active bookings she requested)
INSERT INTO Wallets (user_id, available_balance, escrow_balance) VALUES
  (1, 12.50, 3.50),   -- Avery: 3.50 locked (React audit 2.0 + faucet 1.5)
  (2, 21.00, 0.00),   -- Mara
  (3,  8.00, 0.00),   -- Idris  (becomes 10.00 after the escrow-release demo)
  (4, 15.00, 0.00),   -- Lucia
  (5,  6.50, 0.00),   -- Tom
  (6, 18.00, 0.00),   -- Priya
  (7, 24.00, 0.00);   -- Grace

-- ---- Categories ----  (mirrors client/src/data/categories.js)
INSERT INTO Categories (category_name) VALUES
  ('Design'),          -- 1
  ('Development'),     -- 2
  ('Tutoring'),       -- 3
  ('Home & Repair'),  -- 4
  ('Wellness'),       -- 5
  ('Writing'),        -- 6
  ('Music'),          -- 7
  ('Languages'),      -- 8
  ('Photography'),    -- 9
  ('Business');       -- 10

-- ---- Skills ----  (skill_id in insertion order)
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
  (10, 'Go-to-Market Strategy'); -- 14

-- ---- User_Skills ----  (who can do what)
INSERT INTO User_Skills (user_id, skill_id) VALUES
  (1, 1),                       -- Avery: UI/UX Design
  (2, 2), (2, 3), (2, 5),       -- Mara:  Brand, Design Systems, Web Dev
  (3, 4),                       -- Idris: React
  (4, 12),                      -- Lucia: Spanish
  (5, 7), (5, 8),               -- Tom:   Plumbing, Furniture Assembly
  (6, 9),                       -- Priya: Yoga
  (7, 6);                       -- Grace: Calculus

-- ---- Listings ----  (all 'Offer' type; listing_id in insertion order)
--   Note the category spread that makes the HAVING demo meaningful:
--   Design has 3 active listings, Development 2, Home & Repair 2.
INSERT INTO Listings (user_id, skill_id, listing_type, title, description, estimated_hours, status) VALUES
  (2, 2,  'Offer', 'Brand identity & logo direction',   'Define voice, type, and a first logo direction you can run with.', 3.00, 'active'),  -- 1 Design
  (2, 3,  'Offer', 'Figma design system setup',         'Tokens, core components, and a variables setup your team can build on.', 4.00, 'active'), -- 2 Design
  (1, 1,  'Offer', 'Product design critique',           'A focused critique of your product UI with actionable notes.', 1.50, 'active'),      -- 3 Design (Avery)
  (3, 4,  'Offer', 'React performance audit',           'Screen-share audit: render bottlenecks, bundle weight, prioritized fixes.', 2.00, 'active'), -- 4 Development
  (2, 5,  'Offer', 'Website design & build sprint',     'Soup-to-nuts: design, build, and ship a small marketing site.', 16.00, 'active'),    -- 5 Development
  (4, 12, 'Offer', 'Conversational Spanish, 1-on-1',    'Natural, low-pressure speaking practice tuned to your level.', 1.00, 'active'),      -- 6 Languages
  (5, 7,  'Offer', 'Leaky faucet & sink repair',        'Diagnose and fix drips, slow drains, and worn cartridges.', 1.50, 'active'),         -- 7 Home & Repair
  (5, 8,  'Offer', 'Flat-pack furniture assembly',      'Wardrobes, shelves, desks — assembled, leveled, and anchored safely.', 2.00, 'active'), -- 8 Home & Repair
  (6, 9,  'Offer', 'Vinyasa flow, private session',     'A private flow shaped to your body — mobility, breath, calm.', 1.00, 'active'),       -- 9 Wellness
  (7, 6,  'Offer', 'Calculus tutoring (AP / college)',  'Limits, derivatives, integrals — untangled with worked examples.', 1.00, 'active'),  -- 10 Tutoring
  (2, 2,  'Offer', 'Logo refresh (archived sample)',    'An older listing kept inactive to demo the status filter.', 2.00, 'inactive');       -- 11 (inactive)

-- ---- Bookings ----
--   ACTIVE (drive Avery's dashboard + the escrow demo):
--     b1: Avery requests Idris's React audit (in_progress)  -> 2.00 in Avery's escrow
--     b2: Avery requests Tom's faucet repair  (pending)     -> 1.50 in Avery's escrow
--     b3: Priya requests Avery's design critique (in_progress) -> Avery is the provider
--   COMPLETED (already paid out -> each has a Transactions + Reviews row):
INSERT INTO Bookings (listing_id, requester_id, provider_id, agreed_hours, booking_status) VALUES
  (4,  1, 3, 2.00, 'in_progress'),  -- 1  Avery -> Idris  (React audit)   *** escrow-release demo target ***
  (7,  1, 5, 1.50, 'pending'),      -- 2  Avery -> Tom    (faucet)
  (3,  6, 1, 1.50, 'in_progress'),  -- 3  Priya -> Avery  (design critique)
  (6,  1, 4, 1.00, 'completed'),    -- 4  Avery -> Lucia  (Spanish)
  (3,  6, 1, 1.50, 'completed'),    -- 5  Priya -> Avery  (design critique)
  (1,  7, 2, 3.00, 'completed'),    -- 6  Grace -> Mara   (brand)
  (4,  5, 3, 2.00, 'completed'),    -- 7  Tom   -> Idris  (React audit)
  (10, 4, 7, 1.00, 'completed'),    -- 8  Lucia -> Grace  (calculus)
  (9,  2, 6, 1.00, 'completed'),    -- 9  Mara  -> Priya  (yoga)
  (2,  3, 2, 4.00, 'completed');    -- 10 Idris -> Mara   (design system)

-- ---- Transactions ----  (one immutable ledger row per COMPLETED booking above)
INSERT INTO Transactions (booking_id, sender_id, receiver_id, hours_transferred) VALUES
  (4,  1, 4, 1.00),   -- Avery paid Lucia
  (5,  6, 1, 1.50),   -- Priya paid Avery
  (6,  7, 2, 3.00),   -- Grace paid Mara
  (7,  5, 3, 2.00),   -- Tom paid Idris
  (8,  4, 7, 1.00),   -- Lucia paid Grace
  (9,  2, 6, 1.00),   -- Mara paid Priya
  (10, 3, 2, 4.00);   -- Idris paid Mara

-- ---- Reviews ----  (reviewer rates the provider; ratings chosen so the
--   platform average lands ~4.57 and the correlated-subquery demo returns a
--   clear subset of "above average" providers)
INSERT INTO Reviews (booking_id, reviewer_id, rating, comment) VALUES
  (4,  1, 5, 'Natural, encouraging practice — left with real vocabulary.'),  -- Lucia gets 5
  (5,  6, 5, 'Reframed our onboarding in twenty minutes flat. Rebooked.'),   -- Avery gets 5
  (6,  7, 4, 'Strong systems eye; wanted a touch more written documentation.'), -- Mara gets 4
  (7,  5, 5, 'Found the exact renders killing our dashboard. Superb.'),      -- Idris gets 5
  (8,  4, 5, 'Made calculus finally click. Patient and clear.'),            -- Grace gets 5
  (9,  2, 4, 'Lovely, well-paced flow. Felt great afterward.'),             -- Priya gets 4
  (10, 3, 5, 'Tokens and components saved my team weeks of work.');         -- Mara gets 5 (avg 4.5)

-- =============================================================================
--  End of init.sql
-- =============================================================================
