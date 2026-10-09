# Stock-In Scanner

Count newly arrived stock with a camera or USB barcode scanner, then add the count to a
website's stock in one click.

| Part | What it is |
|---|---|
| `index.html` | The scanner. Opens in Chrome or Edge on the warehouse laptop. |
| `stock-api/` | The connector. Upload it into a website, and pick that site's products table and columns from dropdowns. No changes to the website's own code. |
| `demo-database.sql` | Sample shop database for trying it locally. |

## Try it locally (XAMPP)

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Import `demo-database.sql` in phpMyAdmin. It creates the `inventory_demo` database.
3. Copy `index.html` and the `stock-api` folder into `C:\xampp\htdocs\inventory-tool\`.
4. Open http://localhost/inventory-tool/stock-api/setup.php. On localhost no setup key is needed.
   Connect to `inventory_demo` with user `root` and no password, check the columns, and press **Save settings**.
5. Press **Open the scanner connected to this website**, then **Connect**.
6. Type or scan a model number, for example `SIGA-OSD`, then press **OK**. The stock goes up in phpMyAdmin.

## Install on a website

Requires PHP 7.4+ and a MySQL/MariaDB (or SQLite) database. It works next to Laravel or plain PHP sites.

1. **Upload** the `stock-api` folder into the site's public folder.
   - Laravel site: `public/stock-api`. If the whole app sits in `public_html`, use `public_html/stock-api`.
   - Plain PHP site: `public_html/stock-api`.
2. **Open** `https://<site>/stock-api/setup.php`. The page creates `stock-api/setup-key.php` on the server.
   Open that file in the hosting file manager (cPanel → File Manager → Edit), copy the key, and sign in with it.
3. **Database:** on a Laravel site it is read from the site's `.env` automatically. Otherwise enter the details.
4. **Products:** pick the products table, then the columns:
   - **Code / SKU** is what the QR code or barcode on the item contains, for example `sku`, `model_number` or `barcode`.
   - **Also match codes in** is optional. Pick the ID column if your QR labels hold product page links.
   - **Stock**, **Product name**, **Product ID** and **Last updated** are the matching columns on that site.

   Check the preview, try a few codes in **Test a code**, then press **Save settings**.
5. **Scanner:** in the scanner open **Settings → Website**, paste the **API address** and **API token**
   from the setup page's **Connect the scanner** section, press **Test connection**, then **Save**.

Each website runs its own setup. Never copy `config.php` or `setup-key.php` from one site to another:
they hold that site's database password and token. **Make a new token** on the setup page
cuts off every scanner that has the old one.

Use HTTPS. The token travels with every request, and browsers only allow the camera on HTTPS pages (or localhost).

## What the scanner understands

| Scanned | Example |
|---|---|
| A plain code | `SIGA-OSD`, `4801234567897` |
| JSON | `{"code":"SIGA-OSD","name":"Smoke detector"}` (also `sku`, `productId`, `id`) |
| Code and name | `SIGA-OSD\|Smoke detector` |
| A product page link **on the connected website** | `https://<site>/shop/products/1001` (the last part of the path, or `?sku=` / `?code=` / `?id=`) |

Letter case and surrounding spaces don't matter. A code shared by two products is refused,
because the scanner can't tell which one is meant. The setup page warns about these.

## API

For a website that isn't PHP, implement these two calls and the scanner works with it unchanged.
Both need the header `Authorization: Bearer <token>`.

**`GET` product list:** the scanner loads it on start to recognise codes while scanning.

```json
{
  "success": true,
  "count": 2,
  "products": [{ "id": "1001", "code": "SIGA-OSD", "name": "Intelligent Photoelectric Smoke Detector" }],
  "codes": { "SIGA-OSD": "1001", "1001": "1001", "SHARED-CODE": null }
}
```

`codes` maps each upper-cased code to a product id, or to `null` when it belongs to more than one product.

**`POST` add a count:**

```json
{
  "batchId": "B-20261009-143015-7f3a",
  "stationId": "WH-LAPTOP-01",
  "items": [
    { "productId": "1001", "code": "SIGA-OSD", "quantity": 24 },
    { "productId": null, "code": "4801234567897", "quantity": 2 }
  ]
}
```

- `productId` is set when the scanner recognised the product. Otherwise the server looks up `code`.
- Quantities are **added** to stock, never written over it.
- All lines are added, or none are.
- A `batchId` that was already received returns success with `"duplicate": true` and adds nothing,
  so a retry after a dropped connection can't count twice. The same `batchId` with different items returns `409`.

The answer is `{ "success": true, "items": [{ "productId", "code", "name", "quantity", "newStock" }], "message" }`.
Errors are `{ "success": false, "error", "message" }`. The scanner shows `message` to the user.

| Status | Meaning |
|---|---|
| 401 | Missing or wrong token |
| 409 | `batchId` reused for a different count |
| 422 | Invalid request, or codes not found / shared (`notFound`, `ambiguous` list them) |
| 503 | Not set up yet, or the database can't be reached |

Every received count is stored in the `stock_in_batches` table: an audit trail of what was added, when, and from which laptop.

## Troubleshooting

- **"The API token is missing or wrong" although it's right:** the server drops the `Authorization` header.
  The `.htaccess` in `stock-api` fixes this on Apache. Make sure it was uploaded, since hidden files are easy to miss.
- **"Not recognised" on every scan:** check **Code / SKU column** on the setup page, and try the code in **Test a code**.
- **The camera won't start:** the page must be on HTTPS or localhost, and no other app can be using the camera.
