<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

/**
 * Provides idempotent PostgreSQL 18 DDL and realistic Sakila + pgbench seed datasets
 * along with the restricted non-superuser student sandbox role (`lms_student`).
 */
class SakilaSeeder
{
    public const SANDBOX_ROLE = 'lms_student';

    /**
     * Returns SQL statements that provision the restricted non-superuser sandbox role
     * and grant schema-level privileges in `public` without OS or superuser rights.
     */
    public static function getSandboxRoleSql(): string
    {
        return <<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_student') THEN
        CREATE ROLE lms_student WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD 'postgres';
    ELSE
        ALTER ROLE lms_student WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
    END IF;
END $$;

REVOKE pg_execute_server_program, pg_read_server_files, pg_write_server_files FROM lms_student;
GRANT pg_read_all_stats TO lms_student;
GRANT USAGE, CREATE ON SCHEMA public TO lms_student;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lms_student;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO lms_student;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lms_student;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO lms_student;
SQL;
    }

    /**
     * Builds the idempotent bootstrap SQL script for PostgreSQL 18 extensions,
     * relational Sakila/Pagila tables, pgbench benchmark tables, and sandbox grants.
     */
    public static function getBootstrapSql(bool $forceReset = false, string $dataset = 'all'): string
    {
        $target = strtolower(trim($dataset));
        if (!in_array($target, ['all', 'sakila', 'pgbench'], true)) {
            $target = 'all';
        }

        $parts = [];
        $parts[] = <<<'SQL'
CREATE EXTENSION IF NOT EXISTS pageinspect;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS pgstattuple;
CREATE EXTENSION IF NOT EXISTS postgres_fdw;
SQL;

        if ($target === 'all' || $target === 'sakila') {
            if ($forceReset) {
                $parts[] = 'DROP TABLE IF EXISTS public.payment, public.rental, public.inventory, public.customer, public.staff, public.store, public.address, public.film_category, public.film_actor, public.film, public.category, public.actor CASCADE;';
            }
            $parts[] = self::getSakilaSql();
        }

        if ($target === 'all' || $target === 'pgbench') {
            if ($forceReset) {
                $parts[] = 'DROP TABLE IF EXISTS public.pgbench_history, public.pgbench_tellers, public.pgbench_accounts, public.pgbench_branches CASCADE;';
            }
            $parts[] = self::getPgbenchSql();
        }

        $parts[] = self::getSandboxRoleSql();
        $parts[] = 'ANALYZE;';

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Returns structured dataset metadata for UI diagnostics and CLI tooling.
     */
    public static function getDatasetCatalogSummary(): array
    {
        return [
            'sandbox_role' => self::SANDBOX_ROLE,
            'extensions' => ['pageinspect', 'pg_stat_statements', 'pgstattuple', 'postgres_fdw'],
            'sakila_tables' => [
                'actor' => 14,
                'category' => 10,
                'film' => 18,
                'film_actor' => 26,
                'film_category' => 20,
                'address' => 12,
                'store' => 2,
                'staff' => 2,
                'customer' => 12,
                'inventory' => 30,
                'rental' => 24,
                'payment' => 24,
            ],
            'pgbench_tables' => [
                'pgbench_branches' => 2,
                'pgbench_tellers' => 20,
                'pgbench_accounts' => 1000,
                'pgbench_history' => 10,
            ],
        ];
    }

    private static function getSakilaSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS public.actor (
    actor_id serial PRIMARY KEY,
    first_name varchar(45) NOT NULL,
    last_name varchar(45) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.category (
    category_id serial PRIMARY KEY,
    name varchar(25) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.film (
    film_id serial PRIMARY KEY,
    title varchar(255) NOT NULL,
    description text,
    release_year int,
    language_id smallint NOT NULL DEFAULT 1,
    rental_duration smallint NOT NULL DEFAULT 3,
    rental_rate numeric(4,2) NOT NULL DEFAULT 4.99,
    length smallint,
    replacement_cost numeric(5,2) NOT NULL DEFAULT 19.99,
    rating varchar(10) DEFAULT 'G',
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.film_actor (
    actor_id int NOT NULL REFERENCES public.actor(actor_id) ON DELETE CASCADE,
    film_id int NOT NULL REFERENCES public.film(film_id) ON DELETE CASCADE,
    last_update timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (actor_id, film_id)
);

CREATE TABLE IF NOT EXISTS public.film_category (
    film_id int NOT NULL REFERENCES public.film(film_id) ON DELETE CASCADE,
    category_id int NOT NULL REFERENCES public.category(category_id) ON DELETE CASCADE,
    last_update timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (film_id, category_id)
);

CREATE TABLE IF NOT EXISTS public.address (
    address_id serial PRIMARY KEY,
    address varchar(50) NOT NULL,
    address2 varchar(50),
    district varchar(20) NOT NULL,
    city_id smallint NOT NULL DEFAULT 1,
    postal_code varchar(10),
    phone varchar(20) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.store (
    store_id serial PRIMARY KEY,
    manager_staff_id smallint NOT NULL,
    address_id int NOT NULL REFERENCES public.address(address_id),
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.staff (
    staff_id serial PRIMARY KEY,
    first_name varchar(45) NOT NULL,
    last_name varchar(45) NOT NULL,
    address_id int NOT NULL REFERENCES public.address(address_id),
    email varchar(50),
    store_id int NOT NULL REFERENCES public.store(store_id),
    active boolean NOT NULL DEFAULT true,
    username varchar(16) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.customer (
    customer_id serial PRIMARY KEY,
    store_id int NOT NULL REFERENCES public.store(store_id),
    first_name varchar(45) NOT NULL,
    last_name varchar(45) NOT NULL,
    email varchar(50),
    address_id int NOT NULL REFERENCES public.address(address_id),
    activebool boolean NOT NULL DEFAULT true,
    create_date date NOT NULL DEFAULT CURRENT_DATE,
    last_update timestamptz DEFAULT now(),
    active int DEFAULT 1
);

CREATE TABLE IF NOT EXISTS public.inventory (
    inventory_id serial PRIMARY KEY,
    film_id int NOT NULL REFERENCES public.film(film_id),
    store_id int NOT NULL REFERENCES public.store(store_id),
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.rental (
    rental_id serial PRIMARY KEY,
    rental_date timestamptz NOT NULL DEFAULT now(),
    inventory_id int NOT NULL REFERENCES public.inventory(inventory_id),
    customer_id int NOT NULL REFERENCES public.customer(customer_id),
    return_date timestamptz,
    staff_id int NOT NULL REFERENCES public.staff(staff_id),
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.payment (
    payment_id serial PRIMARY KEY,
    customer_id int NOT NULL REFERENCES public.customer(customer_id),
    staff_id int NOT NULL REFERENCES public.staff(staff_id),
    rental_id int NOT NULL REFERENCES public.rental(rental_id),
    amount numeric(5,2) NOT NULL,
    payment_date timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_actor_last_name ON public.actor (last_name);
CREATE INDEX IF NOT EXISTS idx_film_title ON public.film (title);
CREATE INDEX IF NOT EXISTS idx_inventory_film_id ON public.inventory (film_id);
CREATE INDEX IF NOT EXISTS idx_rental_date ON public.rental (rental_date);
CREATE INDEX IF NOT EXISTS idx_payment_customer_id ON public.payment (customer_id);

INSERT INTO public.actor (actor_id, first_name, last_name) VALUES
    (1, 'PENELOPE', 'GUINESS'),
    (2, 'NICK', 'WAHLBERG'),
    (3, 'ED', 'CHASE'),
    (4, 'JENNIFER', 'DAVIS'),
    (5, 'JOHNNY', 'LOLLOBRIGIDA'),
    (6, 'BETTE', 'NICHOLSON'),
    (7, 'GRACE', 'MOSTEL'),
    (8, 'MATTHEW', 'JOHANSSON'),
    (9, 'JOE', 'SWANK'),
    (10, 'CHRISTIAN', 'GABLE'),
    (11, 'ZERO', 'CAGE'),
    (12, 'KARL', 'BERRY'),
    (13, 'UMA', 'WOOD'),
    (14, 'VIVIEN', 'BERGEN')
ON CONFLICT (actor_id) DO NOTHING;

INSERT INTO public.category (category_id, name) VALUES
    (1, 'Action'),
    (2, 'Animation'),
    (3, 'Children'),
    (4, 'Classics'),
    (5, 'Comedy'),
    (6, 'Documentary'),
    (7, 'Drama'),
    (8, 'Family'),
    (9, 'Sci-Fi'),
    (10, 'Thriller')
ON CONFLICT (category_id) DO NOTHING;

INSERT INTO public.film (film_id, title, description, release_year, rental_duration, rental_rate, length, replacement_cost, rating) VALUES
    (1, 'ACADEMY DINOSAUR', 'An Epic Drama of a Feminist And a Mad Scientist', 2006, 6, 0.99, 86, 20.99, 'PG'),
    (2, 'ACE GOLDFINGER', 'An Astounding Epistle of a Database Administrator And a Explorer', 2006, 3, 4.99, 48, 12.99, 'G'),
    (3, 'ADAPTATION HOLES', 'An Astounding Reflection of a Lumberjack And a Car', 2006, 7, 2.99, 50, 18.99, 'NC-17'),
    (4, 'AFFAIR PREJUDICE', 'A Fanciful Documentary of a Frisbee And a Lumberjack', 2006, 5, 2.99, 117, 26.99, 'G'),
    (5, 'AFRICAN EGG', 'A Fast-Paced Documentary of a Pastry Chef And a Dentist', 2006, 6, 2.99, 130, 22.99, 'G'),
    (6, 'AGENT TRUMAN', 'An Intrepid Panorama of a Robot And a Boy', 2006, 3, 2.99, 169, 17.99, 'PG'),
    (7, 'AIRPLANE SIERRA', 'A Touching Saga of a Hunter And a Butler', 2006, 6, 4.99, 62, 28.99, 'PG-13'),
    (8, 'AIRPORT POLLOCK', 'An Epic Tale of a Moose And a Girl', 2006, 6, 4.99, 54, 15.99, 'R'),
    (9, 'ALABAMA DEVIL', 'A Thoughtful Panorama of a Database Administrator And a Mad Scientist', 2006, 3, 2.99, 114, 21.99, 'PG-13'),
    (10, 'ALADDIN CALENDAR', 'An Action-Packed Tale of a Man And a Lumberjack', 2006, 6, 4.99, 63, 24.99, 'NC-17'),
    (11, 'ALAMO VIDEOTAPE', 'A Boring Epistle of a Butler And a Cat', 2006, 6, 0.99, 126, 16.99, 'G'),
    (12, 'ALASKA PHANTOM', 'A Fanciful Saga of a Hunter And a Pastry Chef', 2006, 6, 0.99, 136, 22.99, 'PG'),
    (13, 'ALI FOREVER', 'A Action-Packed Drama of a Dentist And a Crocodile', 2006, 4, 4.99, 150, 21.99, 'PG'),
    (14, 'ALICE FANTASIA', 'An Emotional Drama of a A Shark And a Database Administrator', 2006, 6, 0.99, 94, 23.99, 'NC-17'),
    (15, 'ALIEN CENTER', 'A Brilliant Drama of a Cat And a Mad Scientist', 2006, 5, 2.99, 46, 10.99, 'NC-17'),
    (16, 'ALLEY EVOLUTION', 'A Fast-Paced Drama of a Robot And a Composer', 2006, 6, 2.99, 180, 23.99, 'NC-17'),
    (17, 'ALONE TRIP', 'A Fast-Paced Character Study of a Composer And a Dog', 2006, 3, 0.99, 82, 14.99, 'R'),
    (18, 'AMADEUS HOLY', 'A Emotional Display of a Pioneer And a Technical Writer', 2006, 6, 0.99, 113, 20.99, 'PG')
ON CONFLICT (film_id) DO NOTHING;

INSERT INTO public.film_actor (actor_id, film_id) VALUES
    (1, 1), (10, 1), (2, 2), (3, 2), (4, 3), (5, 3),
    (6, 4), (7, 4), (8, 5), (9, 6), (11, 7), (12, 8),
    (13, 9), (14, 10), (1, 11), (2, 12), (3, 13), (4, 14),
    (5, 15), (6, 16), (7, 17), (8, 18), (9, 1), (10, 6),
    (11, 9), (12, 13)
ON CONFLICT (actor_id, film_id) DO NOTHING;

INSERT INTO public.film_category (film_id, category_id) VALUES
    (1, 6), (2, 7), (3, 10), (4, 6), (5, 8), (6, 9),
    (7, 5), (8, 1), (9, 10), (10, 1), (11, 5), (12, 2),
    (13, 7), (14, 4), (15, 9), (16, 4), (17, 7), (18, 3),
    (1, 8), (6, 1)
ON CONFLICT (film_id, category_id) DO NOTHING;

INSERT INTO public.address (address_id, address, district, city_id, postal_code, phone) VALUES
    (1, '47 MySakila Drive', 'Alberta', 300, 'T1X0L4', '403-555-0101'),
    (2, '28 MySQL Boulevard', 'QLD', 576, '4000', '617-555-0102'),
    (3, '23 Workhaven Lane', 'Alberta', 300, 'T1X0L5', '403-555-0103'),
    (4, '1411 Lillydale Drive', 'QLD', 576, '4001', '617-555-0104'),
    (5, '1913 Hanoi Way', 'Nagasaki', 463, '35200', '958-555-0105'),
    (6, '1121 Loja Avenue', 'California', 449, '17886', '626-555-0106'),
    (7, '692 Joliet Street', 'Attika', 38, '83579', '210-555-0107'),
    (8, '1566 Inegl Manor', 'Mandalay', 349, '53561', '952-555-0108'),
    (9, '53 Idfu Parkway', 'Nantou', 361, '42399', '886-555-0109'),
    (10, '1795 Santiago de Compostela Way', 'Texas', 295, '18743', '512-555-0110'),
    (11, '900 Santiago de Compostela Parkway', 'Central', 42, '93896', '310-555-0111'),
    (12, '478 Joliet Way', 'Hamilton', 210, '77948', '905-555-0112')
ON CONFLICT (address_id) DO NOTHING;

INSERT INTO public.store (store_id, manager_staff_id, address_id) VALUES
    (1, 1, 1),
    (2, 2, 2)
ON CONFLICT (store_id) DO NOTHING;

INSERT INTO public.staff (staff_id, first_name, last_name, address_id, email, store_id, active, username) VALUES
    (1, 'Mike', 'Hillyer', 3, 'mike.hillyer@sakilastaff.com', 1, true, 'Mike'),
    (2, 'Jon', 'Stephens', 4, 'jon.stephens@sakilastaff.com', 2, true, 'Jon')
ON CONFLICT (staff_id) DO NOTHING;

INSERT INTO public.customer (customer_id, store_id, first_name, last_name, email, address_id, activebool, create_date, active) VALUES
    (1, 1, 'MARY', 'SMITH', 'mary.smith@sakilacustomer.org', 5, true, '2025-02-14', 1),
    (2, 1, 'PATRICIA', 'JOHNSON', 'patricia.johnson@sakilacustomer.org', 6, true, '2025-02-14', 1),
    (3, 1, 'LINDA', 'WILLIAMS', 'linda.williams@sakilacustomer.org', 7, true, '2025-02-15', 1),
    (4, 2, 'BARBARA', 'JONES', 'barbara.jones@sakilacustomer.org', 8, true, '2025-02-16', 1),
    (5, 1, 'ELIZABETH', 'BROWN', 'elizabeth.brown@sakilacustomer.org', 9, true, '2025-02-18', 1),
    (6, 2, 'JENNIFER', 'DAVIS', 'jennifer.davis@sakilacustomer.org', 10, true, '2025-02-20', 1),
    (7, 1, 'MARIA', 'MILLER', 'maria.miller@sakilacustomer.org', 11, true, '2025-03-01', 1),
    (8, 2, 'SUSAN', 'WILSON', 'susan.wilson@sakilacustomer.org', 12, true, '2025-03-04', 1),
    (9, 2, 'MARGARET', 'MOORE', 'margaret.moore@sakilacustomer.org', 5, true, '2025-03-10', 1),
    (10, 1, 'DOROTHY', 'TAYLOR', 'dorothy.taylor@sakilacustomer.org', 6, true, '2025-03-12', 1),
    (11, 2, 'LISA', 'ANDERSON', 'lisa.anderson@sakilacustomer.org', 7, true, '2025-03-15', 1),
    (12, 1, 'SANDRA', 'MARTIN', 'sandra.martin@sakilacustomer.org', 8, false, '2025-03-20', 0)
ON CONFLICT (customer_id) DO NOTHING;

INSERT INTO public.inventory (inventory_id, film_id, store_id) VALUES
    (1, 1, 1), (2, 1, 2), (3, 2, 1), (4, 2, 2), (5, 3, 1),
    (6, 4, 1), (7, 4, 2), (8, 5, 1), (9, 6, 1), (10, 6, 2),
    (11, 7, 1), (12, 7, 2), (13, 8, 1), (14, 9, 2), (15, 10, 1),
    (16, 10, 2), (17, 11, 1), (18, 12, 2), (19, 13, 1), (20, 14, 2),
    (21, 15, 1), (22, 16, 2), (23, 17, 1), (24, 18, 2), (25, 1, 1),
    (26, 5, 2), (27, 8, 2), (28, 11, 2), (29, 13, 2), (30, 18, 1)
ON CONFLICT (inventory_id) DO NOTHING;

INSERT INTO public.rental (rental_id, rental_date, inventory_id, customer_id, return_date, staff_id) VALUES
    (1, '2026-01-05 14:20:00+01', 1, 1, '2026-01-08 16:10:00+01', 1),
    (2, '2026-01-06 10:05:00+01', 3, 2, '2026-01-09 11:30:00+01', 1),
    (3, '2026-01-07 18:45:00+01', 5, 3, '2026-01-12 09:15:00+01', 2),
    (4, '2026-01-08 12:00:00+01', 6, 4, '2026-01-11 17:40:00+01', 2),
    (5, '2026-01-09 15:30:00+01', 8, 5, '2026-01-13 14:00:00+01', 1),
    (6, '2026-01-10 09:50:00+01', 9, 6, '2026-01-13 18:20:00+01', 2),
    (7, '2026-01-11 16:15:00+01', 11, 7, '2026-01-16 12:10:00+01', 1),
    (8, '2026-01-12 11:25:00+01', 13, 8, '2026-01-15 10:00:00+01', 1),
    (9, '2026-01-13 19:00:00+01', 14, 9, '2026-01-17 15:45:00+01', 2),
    (10, '2026-01-14 13:10:00+01', 15, 10, '2026-01-19 16:30:00+01', 1),
    (11, '2026-01-15 17:40:00+01', 17, 11, '2026-01-18 11:20:00+01', 1),
    (12, '2026-01-16 14:05:00+01', 18, 1, '2026-01-20 13:55:00+01', 2),
    (13, '2026-01-17 10:30:00+01', 19, 2, '2026-01-21 09:40:00+01', 1),
    (14, '2026-01-18 16:50:00+01', 20, 3, '2026-01-23 17:15:00+01', 2),
    (15, '2026-01-19 11:15:00+01', 21, 4, '2026-01-22 14:25:00+01', 1),
    (16, '2026-01-20 15:20:00+01', 22, 5, '2026-01-26 12:00:00+01', 2),
    (17, '2026-01-21 18:10:00+01', 23, 6, '2026-01-24 10:50:00+01', 1),
    (18, '2026-01-22 12:45:00+01', 24, 7, '2026-01-27 16:05:00+01', 2),
    (19, '2026-01-23 14:30:00+01', 25, 8, '2026-01-28 11:45:00+01', 1),
    (20, '2026-02-01 09:15:00+01', 2, 1, NULL, 1),
    (21, '2026-02-02 14:40:00+01', 4, 3, NULL, 2),
    (22, '2026-02-03 17:05:00+01', 10, 6, NULL, 2),
    (23, '2026-02-04 11:50:00+01', 16, 9, NULL, 1),
    (24, '2026-02-05 16:25:00+01', 27, 11, NULL, 2)
ON CONFLICT (rental_id) DO NOTHING;

INSERT INTO public.payment (payment_id, customer_id, staff_id, rental_id, amount, payment_date) VALUES
    (1, 1, 1, 1, 0.99, '2026-01-05 14:25:00+01'),
    (2, 2, 1, 2, 4.99, '2026-01-06 10:10:00+01'),
    (3, 3, 2, 3, 2.99, '2026-01-07 18:50:00+01'),
    (4, 4, 2, 4, 2.99, '2026-01-08 12:05:00+01'),
    (5, 5, 1, 5, 2.99, '2026-01-09 15:35:00+01'),
    (6, 6, 2, 6, 2.99, '2026-01-10 09:55:00+01'),
    (7, 7, 1, 7, 4.99, '2026-01-11 16:20:00+01'),
    (8, 8, 1, 8, 4.99, '2026-01-12 11:30:00+01'),
    (9, 9, 2, 9, 2.99, '2026-01-13 19:05:00+01'),
    (10, 10, 1, 10, 4.99, '2026-01-14 13:15:00+01'),
    (11, 11, 1, 11, 0.99, '2026-01-15 17:45:00+01'),
    (12, 1, 2, 12, 0.99, '2026-01-16 14:10:00+01'),
    (13, 2, 1, 13, 4.99, '2026-01-17 10:35:00+01'),
    (14, 3, 2, 14, 0.99, '2026-01-18 16:55:00+01'),
    (15, 4, 1, 15, 2.99, '2026-01-19 11:20:00+01'),
    (16, 5, 2, 16, 2.99, '2026-01-20 15:25:00+01'),
    (17, 6, 1, 17, 0.99, '2026-01-21 18:15:00+01'),
    (18, 7, 2, 18, 0.99, '2026-01-22 12:50:00+01'),
    (19, 8, 1, 19, 0.99, '2026-01-23 14:35:00+01'),
    (20, 1, 1, 20, 1.99, '2026-02-01 09:20:00+01'),
    (21, 3, 2, 21, 4.99, '2026-02-02 14:45:00+01'),
    (22, 6, 2, 22, 2.99, '2026-02-03 17:10:00+01'),
    (23, 9, 1, 23, 4.99, '2026-02-04 11:55:00+01'),
    (24, 11, 2, 24, 5.99, '2026-02-05 16:30:00+01')
ON CONFLICT (payment_id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('public.actor', 'actor_id'), COALESCE((SELECT MAX(actor_id) FROM public.actor), 1));
SELECT setval(pg_get_serial_sequence('public.category', 'category_id'), COALESCE((SELECT MAX(category_id) FROM public.category), 1));
SELECT setval(pg_get_serial_sequence('public.film', 'film_id'), COALESCE((SELECT MAX(film_id) FROM public.film), 1));
SELECT setval(pg_get_serial_sequence('public.address', 'address_id'), COALESCE((SELECT MAX(address_id) FROM public.address), 1));
SELECT setval(pg_get_serial_sequence('public.store', 'store_id'), COALESCE((SELECT MAX(store_id) FROM public.store), 1));
SELECT setval(pg_get_serial_sequence('public.staff', 'staff_id'), COALESCE((SELECT MAX(staff_id) FROM public.staff), 1));
SELECT setval(pg_get_serial_sequence('public.customer', 'customer_id'), COALESCE((SELECT MAX(customer_id) FROM public.customer), 1));
SELECT setval(pg_get_serial_sequence('public.inventory', 'inventory_id'), COALESCE((SELECT MAX(inventory_id) FROM public.inventory), 1));
SELECT setval(pg_get_serial_sequence('public.rental', 'rental_id'), COALESCE((SELECT MAX(rental_id) FROM public.rental), 1));
SELECT setval(pg_get_serial_sequence('public.payment', 'payment_id'), COALESCE((SELECT MAX(payment_id) FROM public.payment), 1));
SQL;
    }

    private static function getPgbenchSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS public.pgbench_branches (
    bid int NOT NULL PRIMARY KEY,
    bbalance int,
    filler char(88)
);

CREATE TABLE IF NOT EXISTS public.pgbench_tellers (
    tid int NOT NULL PRIMARY KEY,
    bid int,
    tbalance int,
    filler char(84)
);

CREATE TABLE IF NOT EXISTS public.pgbench_accounts (
    aid int NOT NULL PRIMARY KEY,
    bid int,
    abalance int,
    filler char(84)
);

CREATE TABLE IF NOT EXISTS public.pgbench_history (
    tid int,
    bid int,
    aid int,
    delta int,
    mtime timestamp,
    filler char(22)
);

INSERT INTO public.pgbench_branches (bid, bbalance, filler)
SELECT g, 0, ''
FROM generate_series(1, 2) AS g
ON CONFLICT (bid) DO NOTHING;

INSERT INTO public.pgbench_tellers (tid, bid, tbalance, filler)
SELECT g, ((g - 1) % 2) + 1, 0, ''
FROM generate_series(1, 20) AS g
ON CONFLICT (tid) DO NOTHING;

INSERT INTO public.pgbench_accounts (aid, bid, abalance, filler)
SELECT g, ((g - 1) % 2) + 1, (g * 17) % 10000, ''
FROM generate_series(1, 1000) AS g
ON CONFLICT (aid) DO NOTHING;

INSERT INTO public.pgbench_history (tid, bid, aid, delta, mtime, filler)
SELECT ((g - 1) % 20) + 1, ((g - 1) % 2) + 1, g * 50, (g - 5) * 25, now() - (g || ' minutes')::interval, ''
FROM generate_series(1, 10) AS g
WHERE NOT EXISTS (SELECT 1 FROM public.pgbench_history LIMIT 1);
SQL;
    }
}
