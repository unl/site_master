ALTER TABLE sites ADD production_status ENUM('PRODUCTION', 'DEVELOPMENT', 'ARCHIVED') NOT NULL DEFAULT 'PRODUCTION';
