"""Empty every basket in the Lane AR preview database (tools/arr-shots.cjs calls
this before it fills a basket, so each context starts from the same seven lines).
PREVIEW DATABASE ONLY."""
import sqlite3
c = sqlite3.connect('storage/framework/testing/ar-preview/preview.sqlite')
c.execute('delete from cart_items')
c.commit()
