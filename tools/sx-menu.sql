-- Lane SX: make the preview menu look like the live one: an All Brands mega
-- panel of 80 brands (8 columns -> mg-l) and a 30-link 3-column panel (mg-a).
SET @m := (SELECT id FROM menus WHERE slug='spd-main' LIMIT 1);
INSERT INTO menu_items (menu_id,parent_id,label,url,target_type,visibility,new_tab,position,created_at,updated_at)
  VALUES (@m,NULL,'All Brands','/brands/','custom','always',0,2,NOW(),NOW());
SET @b := LAST_INSERT_ID();
INSERT INTO menu_items (menu_id,parent_id,label,url,target_type,visibility,new_tab,position,created_at,updated_at)
  SELECT @m,@b,name,CONCAT('/brands/',slug,'/'),'custom','always',0,id,NOW(),NOW() FROM brands ORDER BY id LIMIT 80;
INSERT INTO menu_items (menu_id,parent_id,label,url,target_type,visibility,new_tab,position,created_at,updated_at)
  VALUES (@m,NULL,'Skincare','/shop/','custom','always',0,4,NOW(),NOW());
SET @s := LAST_INSERT_ID();
INSERT INTO menu_items (menu_id,parent_id,label,url,target_type,visibility,new_tab,position,created_at,updated_at)
  SELECT @m,@s,name,CONCAT('/collections/',slug,'/'),'custom','always',0,id,NOW(),NOW() FROM categories ORDER BY id LIMIT 30;
-- The preview shows menu 2 (the demo menu), not spd-main: move the children there.
SET @ab := (SELECT id FROM menu_items WHERE menu_id=3 AND label='All Brands'); SET @sk := (SELECT id FROM menu_items WHERE menu_id=3 AND label='Skincare');
SET @B := (SELECT id FROM menu_items WHERE menu_id=2 AND label='Brands' AND parent_id IS NULL); SET @S := (SELECT id FROM menu_items WHERE menu_id=2 AND label='Skincare' AND parent_id IS NULL);
UPDATE menu_items SET menu_id=2, parent_id=@B, position=position+1000 WHERE parent_id=@ab;
UPDATE menu_items SET menu_id=2, parent_id=@S, position=position+1000 WHERE parent_id=@sk;
DELETE FROM menu_items WHERE id IN (@ab,@sk);
