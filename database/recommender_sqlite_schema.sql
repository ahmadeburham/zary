-- Initial schema for recommender/recommender.db (TEXT ids = Sukoon UUIDs)
CREATE TABLE IF NOT EXISTS faculties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    affinity_group TEXT NOT NULL DEFAULT 'UNKNOWN'
);

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    name TEXT,
    email TEXT
);

CREATE TABLE IF NOT EXISTS student_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    budget_min REAL DEFAULT 0,
    budget_max REAL DEFAULT 0,
    preferred_location TEXT,
    prefers_furnished INTEGER,
    faculty_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS apartments (
    id TEXT PRIMARY KEY,
    title TEXT,
    description TEXT,
    price REAL,
    location TEXT,
    latitude REAL,
    longitude REAL,
    is_furnished INTEGER DEFAULT 0,
    capacity INTEGER DEFAULT 1,
    status TEXT DEFAULT 'AVAILABLE'
);

CREATE TABLE IF NOT EXISTS apartment_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    apartment_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    status TEXT DEFAULT 'ACTIVE',
    FOREIGN KEY (apartment_id) REFERENCES apartments(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS model_config_weights (
    "key" TEXT PRIMARY KEY,
    weight REAL NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS affinity_similarity (
    src_group TEXT NOT NULL,
    dst_group TEXT NOT NULL,
    similarity REAL NOT NULL,
    PRIMARY KEY(src_group, dst_group)
);
