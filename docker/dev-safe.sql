-- Yerel kopyada gerçek sistemlere (Bayt, Bulut ödeme, SMTP, Yurtiçi) istek gitmesin.
UPDATE settings SET v = JSON_SET(v, '$.enabled', '0') WHERE k = 'bayt_json' AND JSON_VALID(v);
UPDATE settings SET v = '' WHERE k IN ('bt_username', 'bt_password', 'bt_firm', 'bt_secret', 'bt_web_user', 'bt_web_pass', 'smtp_pass', 'yk_pass');
UPDATE settings SET v = '0' WHERE k IN ('smtp_enabled', 'yk_enabled', 'bt_enabled');
UPDATE settings SET v = 'http://localhost:8088' WHERE k = 'site_url';
UPDATE settings SET v = 'http://localhost:8088/uploads' WHERE k = 'media_base';
