INSERT INTO `categories` (`name`, `icon`)
VALUES
  ('كتب دراسية', 'book-open'),
  ('أجهزة إلكترونية', 'laptop'),
  ('مستلزمات جامعية', 'backpack'),
  ('ملابس وأدوات شخصية', 'shirt'),
  ('أخرى', 'box')
ON DUPLICATE KEY UPDATE
  `icon` = VALUES(`icon`);

