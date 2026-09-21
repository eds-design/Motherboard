# EDS Warranty Cards — stages 1–4 with issuer details, manual parts and warranty terms

Optional module, disabled by default. All code, translations and schema belong to
this directory. No dependency on the author's Warranty module.

## Draft content limits

Version 0.12.0 applies the same server-side limits when a draft is saved and
immediately before it is issued. Manual part names, serial numbers, suppliers
and supplier warranty-card numbers are limited to 128 Unicode characters; work
activity descriptions to 250 Unicode characters; and warranty periods to six
digits. A period may remain empty in a draft, but a supplied value must contain
only digits and represent a positive number. Leading zeroes are preserved.

A draft accepts at most 100 inventory and non-empty manual parts combined, at
most 100 submitted manual rows including empty rows, at most 50 submitted work
activity rows including empty rows, and at most 256 KiB of canonical JSON.
Work orders with more than 100 inventory physical units are not expanded or
partially displayed. Invalid UTF-8, line breaks and control characters are
rejected in single-line fields. Rejections do not save the draft, increment its
revision, issue a card or change the number counter; the previously saved draft
remains intact. Existing issued snapshots are not revalidated or changed.

Enable through Settings → Modules; configure through the standard module settings
page (Admin only, core CSRF validation). The first request after enabling runs the
existing migration manager. Schema version 5 owns `eds_warranty_cards_counter`
(initially next number `1`), `eds_warranty_cards_settings`,
`eds_warranty_cards_drafts`, and
`eds_warranty_cards_issued`. Schema version is
stored in the counter table. Upgrading/re-enabling/re-running migrations preserves
the counter, settings, drafts and issued cards. All DDL is for these four module-owned tables only.

The shared migration manager also runs core schema maintenance and other enabled
module migrations. Check for pending core migrations before activating on an
existing installation. This module never migrates other tables.

Numbers preserve the entered decimal strings in `LONGTEXT`, not PHP integers,
floats or SQL numeric types. Leading zeros are retained in storage, display and
issuance. Only comparison ignores them: `4` and `00004` are numerically equal,
so changing between these formats is allowed. Increment preserves the entered
width (`00004` → `00005`) and expands on overflow (`99999` → `100000`). No business
digit limit is imposed; physical database/request/memory limits still apply.
Comparisons use length and lexical order after removing leading zeros; increment
uses decimal carry. No BCMath/GMP dependency. Changes to the next number's format
never rewrite previously issued numbers. The format correction requires no data
conversion; stage 2 adds the draft table without changing the current number.

Settings saves and issuance use the same InnoDB singleton row lock through
`EdsWarrantyCardCounter`. Issuance persists and locks the card using the PDO passed
to the counter callback, after the counter lock. Returning false for an existing
issuance consumes no number. Database exceptions roll back all related writes.
No other code should update this counter directly.

The first section of the module settings stores optional issuer details in the
module-owned settings row: service and legal names, registration number,
representative, address, phone, email, website and logo URL. The form retains
empty values as empty. At preview and issuance time, an empty service name,
address, phone, email, website or logo uses the corresponding general Motherboard
company setting; legal name, registration number and representative have no
fallback. Text is UTF-8 validated, bounded and kept as plain text. Email and URL
fields are validated on the server. Logo URLs accept only absolute HTTP/HTTPS URLs
or root-relative paths beginning with one slash; the module never fetches them on
the server. Issuer details, terms and the formatted counter are saved through the
same transaction and counter row lock, so a validation or storage error cannot
leave a partial settings update.

The module-owned `eds_warranty_cards_settings.terms_html` `LONGTEXT` stores
optional warranty terms. The settings page progressively enhances its single
normal textarea with the locally bundled Quill 2.0.3 Snow editor. The toolbar is
limited to bold, unordered-list and ordered-list controls, with `formats` limited
to `bold` and `list`. The textarea stays visible and usable when JavaScript or a
local Quill asset fails, and it is hidden only after successful editor
initialization. Quill synchronizes semantic HTML back to that same textarea before
the form is submitted. The server remains the authoritative security boundary.

Quill's unchanged distribution files and BSD-3-Clause license are in
`assets/quill/`; that directory's README records the exact package source,
included files and SHA-256 archive digest. Module-owned asset routes are used
because Motherboard intentionally blocks direct HTTP access to `modules/`.

`EdsWarrantyCardTerms` parses all input with PHP's HTML parser. It allows only
`p`, `br`, `strong`, `ul`, `ol` and `li`, converts `b` to `strong`, strips every
attribute and comment, removes dangerous elements with their full subtrees, and
unwraps safe text from unsupported formatting. Invalid UTF-8 is rejected. The
sanitized visible content is limited to 20,000 UTF-8 characters. `LONGTEXT` is
used because the application's shared `settings.setting_value` is `TEXT` and
cannot hold 20,000 four-byte UTF-8 characters. Settings storage
and counter updates share one transaction and counter lock. The stored value is
sanitized again whenever it is opened or rendered.

The work-order hook adds a warranty card section and links to
`/work-orders/view/{id}/eds-warranty-card/draft`. Admin/Technician can create/edit;
Limited can view an existing draft only. Authentication and CSRF use core helpers.
The review/issuance route is `/work-orders/view/{id}/eds-warranty-card`. There is
no connection to the author's Warranty module.

Opening the editor does not create a draft. Saving permits incomplete fields and
stores one JSON document per work order (primary key), including each unit's
selection and manual fields. The editor can either save in place or save and open
the existing review/issuance screen; both actions use the same revision check and
neither issues a card or consumes a number. Nonempty months for selected parts and
work activities must contain a positive decimal integer.

Inventory is read only: line ID, position name and quantity from
`work_order_products`, with no category/product lookup, filtering or pricing.
Each line expands into independently selectable units keyed by line ID + ordinal.
Existing positions can be read even with Inventory disabled because this module
has no runtime dependency on the Inventory module. A missing table or columns give
a normal empty-state message. Inventory units already saved in a draft remain
visible and editable from the module-owned JSON. Draft writes never update
inventory, core work orders, or the number counter.

The draft editor also supports manual part rows at all times. Each row is one
physical unit with a user-entered name, serial, months, supplier and supplier-card
number. Rows have stable random keys, can be added/removed without creating stock,
and are stored separately from inventory units with an internal `manual` origin.
Empty rows may remain in drafts and do not count as issuance content. A nonempty
manual row must have a name and positive months at issuance. Supplier fields remain
internal; the public preview, immutable public snapshot and print receive only the
name, optional serial and months. Existing draft JSON without origins is accepted
and normalized on its next successful save; issued snapshots are never rewritten.

Saves lock the parent work order, compare the submitted draft revision and verify
the inventory fingerprint. Concurrent first saves produce one draft and a
conflict, not an overwrite. Errors render the submitted values safely without a
redirect. Draft conflicts retain the stale revision and disable saving; open the
latest draft in a new tab to compare/transfer changes. Inventory conflicts refresh
the list; a second save acknowledges it. Removed selected units stay visible until
explicitly deselected. A final hidden sentinel detects truncated POST forms.
Draft rows cascade when a work order is deleted. No issued-card deletion policy
applies before issuance. Internal supplier fields are present only in authenticated
Admin/Technician draft/review screens.

Issuance stores exactly one immutable card per work order. It uses the counter's
transaction and row lock; inside that same transaction it locks the card slot,
parent work order and draft, verifies the preview revision/content token, validates
the final content, inserts the snapshots, then increments the formatted counter.
Repeated/concurrent requests return the existing card without consuming a number.
The numeric identity also has a SHA-256 unique key, allowing exact duplicate
protection without imposing an index-length limit on very large displayed numbers.

The final data is split physically into `public_content` and `internal_content`.
`clientContent()` selects and decodes only the public column. Supplier and supplier
card data never enter that projection or the Limited view. New public content
freezes only the resolved issuer, customer, selected parts, work warranties and
sanitized terms. Card number and issue date remain in their dedicated issued-card
columns. Device and work-order details are deliberately excluded from new customer
snapshots; the database relationship remains the module row's `work_order_id`.
Later source, module or general settings changes cannot alter an issued document.
Older cards without `issuer` continue to render only their original frozen
`company` structure and never receive current issuer settings. Old `device` and
`work_order` fields may remain stored for compatibility but the customer template
never renders them. The draft controller and data layer both
block edits after issuance.

Drafts do not freeze warranty terms. Preview reads the current sanitized setting;
issuance stores it in the immutable public snapshot in the same issuance
transaction. Later setting changes do not alter issued cards. Older cards without
the snapshot field and cards with empty terms render no terms section. Only the
section label follows the selected print language; user-authored terms remain
unchanged.

Admin/Technician must check an explicit confirmation after reviewing the candidate.
Limited can view public content only. The work-order deletion hook shows a specific
message for an issued card, while an `ON DELETE RESTRICT` foreign key remains as a
database backstop even when the module is disabled.

Stage 4 adds the issued-card print route
`/work-orders/view/{id}/eds-warranty-card/print`. It is an authenticated GET-only
route for Admin, Technician and Limited under the same task-view access model as
Motherboard. Only Admin/Technician see the Print button. Missing work orders,
missing cards and drafts redirect to the standard 404 response.

The print controller calls `clientContent()` and passes only that public projection
to its standalone template. It never loads or passes `internal_content`. Preview,
issued-card view and print all include the same `views/client-document.php`
structure. The module-owned `warranty-card-document.css` is served through the
asset allowlist and switches only the surrounding screen/print presentation.
The customer document contains issuer and customer columns, one ordered table for
parts and warranty services, optional sanitized terms, and a final two-column
signature block. It contains no task/device data, layout navigation, forms,
buttons, supplier fields, prices, stock codes, quantities or administrative notes.
The client document is progressively enhanced by the local
`warranty-card-pagination.js` asset into explicit 210 × 297 mm preview sheets with
13 mm inner margins. The same generated sheets are printed with one page break
between them. Pagination waits for document fonts and images, rebuilds from one
pristine source after a viewport resize, repeats the table heading, keeps normal
terms paragraphs and short lists intact when they fit a page, and splits only an
oversized paragraph or list item at natural text boundaries. Signature blocks are
kept together. Warranty-terms body text is 12 px with a 1.45 line height; the rest
of the document typography is unchanged. If JavaScript fails or is disabled, the
original shared customer document remains visible and uses ordinary browser print
flow. Browser printing opens only after pagination completes and supports physical
printing or Save as PDF.

Print labels use the module's BG/EN files after the core `applyPrintLanguage()`
selection and module-language reload. Every print request reloads the immutable
public snapshot, so later source edits and repeated printing cannot change the
document. Responses are private/no-store and noindex.

The module also registers the staff-only `/eds-warranty-cards` issued-card
registry and adds its link through `layout.nav`. Admin and Technician can open it;
Limited retains access to individual permitted cards through work orders but has
no registry route or navigation link. The registry queries only
`eds_warranty_cards_issued`. Its compact four-column page projects the exact
formatted card number, frozen customer name, issue date and links to the existing
view/print routes. Frozen phone data participates in search but is never returned
to the view or emitted in HTML; `internal_content`, current customers, drafts and
work-order details are not queried for display.

Registry filtering, counting, ordering and paging run in MariaDB with prepared
queries and a fixed 50-row page size. Name matching is partial and
case-insensitive, and phone matching is partial. A digits-only query also compares
the existing SHA-256 `number_key`, produced from the normalized decimal string,
so `6` finds `0006` without a PHP/SQL integer conversion and arbitrary-length
numbers retain precision. Exact card-number matches sort before simultaneous
phone matches; otherwise results use issue date descending and work-order ID
descending as a stable tie-breaker. Excessive page values clamp safely to the last
available page, and an active search remains in pagination links.

The `layout.nav` fallback renders one usable link near the end of the core menu.
For Admin and Technician only, the local allowlisted `warranty-card-nav.js`
progressively moves that same element immediately after the exact absolute
`BASE_URL . '/work-orders'` link. It uses the link's unique ID and escaped
`data-work-orders-url`, never translated labels or suffix selectors. A missing
target or failed/disabled JavaScript leaves the original link in place; the script
does not clone it. The registry table keeps its existing mobile card layout, while
desktop CSS aligns only the Actions heading and its two compact buttons to the
right. At 640 px and below the search form resets its desktop flex basis and stacks
the single-line input, Search and optional Clear actions at natural height. Each
mobile card keeps View and Print in one compact action-button group after the
Actions label without stretching either link.

Checks (no application configuration is loaded):

```sh
php public_html/modules/eds-warranty-cards/tests/run.php
```

For transactional/concurrent checks use a disposable MariaDB Unix socket under
`/tmp`, with an `isolated-test-server` marker in its directory, and pass its path
as `EDS_WARRANTY_CARDS_TEST_SOCKET`. The suite recreates tables only in the fixed
`eds_warranty_cards_test` database on that explicitly supplied server.

For the full HTTP smoke test on that same isolated server:

```sh
EDS_WARRANTY_CARDS_TEST_SOCKET=/tmp/your-isolated-server/test.sock \
php -d pdo_mysql.default_socket=/tmp/your-isolated-server/test.sock \
public_html/modules/eds-warranty-cards/tests/http.php
```

This uses a separate fixed `eds_warranty_cards_http_test` database, a temporary
configuration copied from the sample (never the live config), isolated sessions,
and a short-lived HTTP server on an available loopback port. It checks migration
from stage 1, actual routing/layout, Admin/Technician/Limited permissions, CSRF,
form retention, escaping, revisions, confirmation, issuance, client/internal data
separation, immutable snapshots, repeat requests, deletion blocking and unchanged
source data. It also covers the issued-card registry, 50/51-row pagination,
snapshot-only search and role-limited navigation. The HTTP server and its
temporary files are removed at completion.

The browser layout check uses only the locally installed headless Chromium and
module assets; it does not load application configuration or a database:

```sh
php public_html/modules/eds-warranty-cards/tests/pagination-browser.php
```

The navigation enhancement has a separate database-free browser check:

```sh
php public_html/modules/eds-warranty-cards/tests/navigation-browser.php
```

Desktop action alignment and the existing overflow-safe mobile layout are checked
with:

```sh
php public_html/modules/eds-warranty-cards/tests/registry-layout-browser.php
```
