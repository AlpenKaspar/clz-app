-- CLZ App MySQL/MariaDB schema for Metanet/Plesk
-- Import via Plesk phpMyAdmin.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key varchar(120) NOT NULL,
  setting_value longtext NULL,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_cache (
  cache_key varchar(190) NOT NULL,
  cache_value longtext NOT NULL,
  expires_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cache_key),
  KEY idx_app_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_locks (
  lock_name varchar(120) NOT NULL,
  owner_token varchar(120) NOT NULL,
  expires_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lock_name),
  KEY idx_app_locks_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  display_name varchar(190) NULL,
  person_id varchar(80) NULL,
  role varchar(40) NOT NULL DEFAULT 'guest',
  password_hash varchar(255) NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  last_login_at datetime NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people (
  id varchar(80) NOT NULL,
  date_added datetime NULL,
  date_modified datetime NULL,
  firstname varchar(190) NULL,
  preferred_name varchar(190) NULL,
  lastname varchar(190) NULL,
  display_name varchar(255) NULL,
  email varchar(190) NULL,
  phone varchar(80) NULL,
  mobile varchar(80) NULL,
  status varchar(80) NULL,
  category_id varchar(80) NULL,
  category_name varchar(190) NULL,
  family_id varchar(80) NULL,
  gender varchar(80) NULL,
  birthday date NULL,
  home_address varchar(255) NULL,
  home_city varchar(190) NULL,
  home_postcode varchar(40) NULL,
  departments text NULL,
  picture_url text NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_people_name (lastname, firstname),
  KEY idx_people_family (family_id),
  KEY idx_people_category (category_name),
  KEY idx_people_email (email),
  FULLTEXT KEY ft_people_search (firstname, preferred_name, lastname, email, home_address, home_city, departments)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_custom_fields (
  person_id varchar(80) NOT NULL,
  field_id varchar(120) NOT NULL,
  field_name varchar(190) NOT NULL,
  field_value text NULL,
  option_ids text NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (person_id, field_id),
  KEY idx_people_custom_field_name (field_name),
  CONSTRAINT fk_people_custom_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_definitions (
  field_id varchar(120) NOT NULL,
  field_type varchar(80) NULL,
  field_name varchar(190) NOT NULL,
  context_name varchar(190) NULL,
  examples text NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (field_id),
  KEY idx_custom_field_name (field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_options (
  option_id varchar(120) NOT NULL,
  field_id varchar(120) NOT NULL,
  option_name varchar(190) NOT NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (option_id),
  KEY idx_custom_field_options_field (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS families (
  id varchar(80) NOT NULL,
  label varchar(190) NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_members (
  family_id varchar(80) NOT NULL,
  person_id varchar(80) NOT NULL,
  firstname varchar(190) NULL,
  lastname varchar(190) NULL,
  relationship varchar(120) NULL,
  sort_order int NOT NULL DEFAULT 0,
  imported_at datetime NOT NULL,
  PRIMARY KEY (family_id, person_id),
  KEY idx_family_members_person (person_id),
  CONSTRAINT fk_family_members_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  CONSTRAINT fk_family_members_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS groups (
  id varchar(80) NOT NULL,
  date_added datetime NULL,
  date_modified datetime NULL,
  name varchar(255) NOT NULL,
  description text NULL,
  meeting_address varchar(255) NULL,
  meeting_city varchar(190) NULL,
  meeting_country varchar(120) NULL,
  meeting_day varchar(80) NULL,
  meeting_frequency varchar(120) NULL,
  meeting_postcode varchar(40) NULL,
  meeting_state varchar(120) NULL,
  meeting_time varchar(80) NULL,
  picture_url text NULL,
  status varchar(80) NULL,
  category_name text NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
  group_id varchar(80) NOT NULL,
  person_id varchar(80) NOT NULL,
  firstname varchar(190) NULL,
  lastname varchar(190) NULL,
  display_name varchar(255) NULL,
  role varchar(190) NULL,
  position varchar(190) NULL,
  email varchar(190) NULL,
  mobile varchar(80) NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (group_id, person_id, role, position),
  KEY idx_group_members_person (person_id),
  CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_events (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  elvanto_id varchar(120) NOT NULL,
  start_date date NOT NULL,
  start_time time NULL,
  end_date date NULL,
  end_time time NULL,
  title varchar(255) NOT NULL,
  category varchar(190) NULL,
  location varchar(255) NULL,
  details text NULL,
  status varchar(80) NULL,
  category_color varchar(20) NULL,
  category_key varchar(120) NULL,
  modified_raw varchar(120) NULL,
  modified_at datetime NULL,
  resources text NULL,
  predigtskript_url text NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calendar_unique (elvanto_id, start_date, start_time),
  KEY idx_calendar_range (start_date, end_date),
  KEY idx_calendar_category (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  service_id varchar(80) NOT NULL,
  title varchar(255) NOT NULL,
  category varchar(190) NULL,
  location varchar(255) NULL,
  status varchar(80) NULL,
  service_start datetime NULL,
  service_end datetime NULL,
  details text NULL,
  resources text NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (service_id),
  KEY idx_services_start (service_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_times (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  service_id varchar(80) NOT NULL,
  elvanto_time_id varchar(80) NULL,
  starts_at datetime NOT NULL,
  ends_at datetime NULL,
  label varchar(190) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_times (service_id, elvanto_time_id, starts_at),
  KEY idx_service_times_range (starts_at, ends_at),
  CONSTRAINT fk_service_times_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_volunteers (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  service_id varchar(80) NOT NULL,
  person_id varchar(80) NULL,
  display_name varchar(255) NULL,
  role varchar(190) NULL,
  status varchar(80) NULL,
  team varchar(190) NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_service_volunteers_service (service_id),
  KEY idx_service_volunteers_person (person_id),
  CONSTRAINT fk_service_volunteers_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_plan_items (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  service_id varchar(80) NOT NULL,
  item_order int NOT NULL DEFAULT 0,
  title varchar(255) NULL,
  item_type varchar(120) NULL,
  starts_at datetime NULL,
  duration_min int NULL,
  description text NULL,
  song_title varchar(255) NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_service_plan_service (service_id, item_order),
  CONSTRAINT fk_service_plan_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS songs (
  song_id varchar(80) NOT NULL,
  title varchar(255) NOT NULL,
  artist varchar(255) NULL,
  category varchar(190) NULL,
  default_key_name varchar(80) NULL,
  bpm varchar(40) NULL,
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (song_id),
  KEY idx_songs_title (title),
  FULLTEXT KEY ft_songs_search (title, artist, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  import_type varchar(80) NOT NULL,
  status varchar(40) NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  item_count int NULL,
  message text NULL,
  meta_json longtext NULL,
  PRIMARY KEY (id),
  KEY idx_import_runs_type_started (import_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_media_resources (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  resource_type varchar(40) NOT NULL,
  resource_key varchar(190) NOT NULL,
  service_date date NOT NULL,
  service_time time NULL,
  title varchar(255) NOT NULL DEFAULT '',
  speaker varchar(190) NOT NULL DEFAULT '',
  url text NOT NULL,
  video_id varchar(40) NULL,
  thumbnail_url text NULL,
  scheduled_at datetime NULL,
  source varchar(120) NOT NULL DEFAULT '',
  raw_json longtext NULL,
  imported_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_media_resource (resource_type, resource_key),
  KEY idx_service_media_date (service_date, service_time),
  KEY idx_service_media_video (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_smart_filters (
  user_id bigint unsigned NOT NULL,
  filter_key varchar(120) NOT NULL,
  payload_json longtext NOT NULL,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, filter_key),
  CONSTRAINT fk_user_smart_filters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_preferences (
  user_id bigint unsigned NOT NULL,
  preference_key varchar(120) NOT NULL,
  payload_json longtext NOT NULL,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, preference_key),
  CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint unsigned NOT NULL,
  endpoint text NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh text NULL,
  auth text NULL,
  user_agent text NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  last_seen_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
  KEY idx_push_user_active (user_id, is_active),
  CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_notification_log (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint unsigned NOT NULL,
  notification_key varchar(190) NOT NULL,
  sent_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_notification_log_user_key (user_id, notification_key),
  KEY idx_push_notification_log_sent (sent_at),
  CONSTRAINT fk_push_notification_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_pending_notifications (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint unsigned NOT NULL,
  notification_key varchar(190) NOT NULL,
  title varchar(190) NOT NULL,
  body text NULL,
  url text NULL,
  tag varchar(190) NULL,
  created_at datetime NOT NULL,
  consumed_at datetime NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_pending_user_key (user_id, notification_key),
  KEY idx_push_pending_user (user_id, consumed_at, created_at),
  CONSTRAINT fk_push_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_sessions (
  session_id varchar(120) NOT NULL,
  user_email varchar(190) NULL,
  active_card_id varchar(120) NULL,
  person_count int NOT NULL DEFAULT 1,
  started_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  ended_at datetime NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  meta_json longtext NULL,
  PRIMARY KEY (session_id),
  KEY idx_prayer_sessions_active (is_active, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_points (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_email varchar(190) NOT NULL,
  points int NOT NULL DEFAULT 0,
  awarded_at datetime NOT NULL,
  session_id varchar(120) NULL,
  PRIMARY KEY (id),
  KEY idx_prayer_points_user (user_email),
  KEY idx_prayer_points_awarded (awarded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_pools (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  pool_name varchar(190) NOT NULL,
  created_by_email varchar(190) NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prayer_pool_name (pool_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_pool_members (
  pool_id bigint unsigned NOT NULL,
  person_id varchar(80) NOT NULL,
  added_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pool_id, person_id),
  KEY idx_prayer_pool_members_person (person_id),
  CONSTRAINT fk_prayer_pool_members_pool FOREIGN KEY (pool_id) REFERENCES prayer_pools(id) ON DELETE CASCADE,
  CONSTRAINT fk_prayer_pool_members_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_checkins (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_key varchar(190) NOT NULL,
  service_id varchar(120) NULL,
  event_id varchar(120) NULL,
  service_date date NOT NULL,
  service_time varchar(8) NOT NULL,
  service_title varchar(190) NULL,
  person_id varchar(80) NOT NULL,
  group_label varchar(120) NULL,
  checked_in_by bigint unsigned NULL,
  checked_in_at datetime NOT NULL,
  checked_out_at datetime NULL,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kids_checkins_session_person (session_key, person_id),
  KEY idx_kids_checkins_session (session_key),
  KEY idx_kids_checkins_person_active (person_id, checked_out_at),
  KEY idx_kids_checkins_date (service_date, service_time),
  CONSTRAINT fk_kids_checkins_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_checkins_user FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
