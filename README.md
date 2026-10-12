# Dahu Serial Numbers

Per-unit traceability for WooCommerce. One row per physical unit you ship, a
lifecycle that refuses the moves that make no sense, an append-only history,
and a packing screen where the number is scanned at the bench rather than typed
from memory afterwards.

MIT licensed. Requires WordPress 6.0, PHP 7.4 and WooCommerce 7.0. No external
service, no account anywhere, no vendor directory.

## What it is for

Two questions, asked months apart.

A customer calls about a motor that has failed. Which one did they get, which
batch was it from, and how many others from that batch went out? Without serial
numbers the answer is the order date and a guess.

A batch turns out to be faulty. Which customers have one, and which units are
still on the shelf? Without serial numbers the answer is everybody who bought
that product, which is both too many people to write to and not the right ones.

This plugin answers both, because every unit has a row and every row has a
history.

## The lifecycle

Eleven statuses, and a matrix that says which moves are legal:

```
received ─→ in stock ─→ assigned ─→ shipped ─→ return expected ─┬→ returned, as described ─┬→ in repair ─→ replaced
                 ↑           │          │                       │                          ├→ going back to stock ─→ in stock
                 └───────────┴──────────┘                       └→ returned, not as described
                                                                                           └→ destroyed
```

Every status change goes through one check. There is no second door, and no
flag anywhere that turns the check off.

Three of the shapes are worth explaining:

- **Assigned → in stock** is an explicit un-assignment, logged like any other
  move. Without it, freeing a unit means editing a row by hand.
- **Shipped → in stock** exists for one real case: an order moved back from
  completed to processing. Forcing a detour through *assigned* would make the
  history say something that did not happen.
- **Returned, not as described** has no outlet. It is an alert state: what to
  do with a unit that came back as something other than what was expected is a
  decision, and the plugin refuses to guess it.

A unit put back in stock is a free unit, so the order, the order line, the
service ticket and the replacement pointer are all cleared. Nothing is lost —
what was cleared is written into the history line.

A shop with a step of its own — a quarantine, a second inspection — widens the
matrix with a filter rather than writing statuses behind the plugin's back.
Targets that are not known statuses are ignored: a typo in a filter must not
open a door.

## The packing screen

A page of its own, at `/packing/`, off until you switch it on.

It is not an admin screen, because the person using it is not an administrator
and should not have to be. Packing is somebody at a bench with a barcode
reader. Giving them a WordPress login so they can scan serial numbers means
giving them wp-admin, and taking it away when they leave means remembering to.

So the screen signs in against **workshop posts** — one login per bench, not
one per person. A post is a tablet on a workbench, used by whoever is packing.
The history records which post did what, which is the question somebody
actually asks: not *who* packed this parcel, but *where*.

The screen:

- lists the orders waiting to be packed, and how many units each still owes;
- takes one scan at a time and checks it against the shop as it happens — a
  unit already on another order, or not in stock, is refused with a sentence
  saying which;
- refuses a line more units than it ordered;
- will not close a parcel while a unit on it is unaccounted for;
- records the carrier and tracking number on the order when the parcel goes.

A barcode reader is a keyboard, and plenty of suppliers encode a URL on the
label rather than the number. The whole address lands in the field, and the
plugin reduces it to the serial — refusing it would be correct and useless,
because the person at the bench has no other way to enter that number.

## Workshop posts

Created on **Serial numbers → Workshop posts**. A post has a login, a password
of at least twelve characters, and a name that shows on the screen and in the
history.

Switching a post off, or replacing its password, signs it out everywhere it was
signed in — immediately, not when its session happens to run out. A post is
never deleted: the history points at post ids, and a line that answers "where
was this parcel packed" with a missing id answers nothing.

## Shipping, and what is deliberately not here

The plugin closes a parcel: every unit in it is recorded as having left, the
carrier and tracking number go on the order where support will look for them,
and the order moves on. That works on any shop, with no account anywhere.

It does **not** talk to a carrier. No label creation, no API credentials, no
parcel-type mapping — every line of that needs an account, a contract and a
configuration particular to one shop, and shipping an interface with nothing
behind it would be shipping a feature that does nothing until somebody writes a
PHP class.

### Grafting your carrier onto it

> **Upgrading from 1.0.0?** `dahu_serial_numbers_before_shipment` gained a
> parameter in **second** position — `$consignment_id` — so `$order` is now the
> third. A listener registered with `10, 4` and the old order of arguments
> receives an integer where it expects the order, and fails; the extension
> catches that, closes the parcel anyway, and the only trace is a note on the
> order. Update the signature to `( $tracking, $consignment_id, $order,
> $carrier, $station_id )` and register it with `10, 5`.

A carrier integration is a plugin of its own — thirty lines, two filters, and
no changes here. Whatever you have a contract with: Chronopost, Colissimo,
Sendcloud, a freight forwarder, a courier who takes a CSV once a day.

```php
<?php
/**
 * Plugin Name: My carrier for Dahu Serial Numbers
 */

// 1. Create the label when the bench closes the parcel, and hand back the
//    tracking number. Whatever you return is stored on the order and written
//    into every unit's history.
add_filter(
    'dahu_serial_numbers_before_shipment',
    function ( $tracking, $consignment_id, $order, $carrier, $station_id ) {

        // Already have one — a label printed earlier, a number typed at the
        // bench? Leave it alone.
        if ( '' !== $tracking ) {
            return $tracking;
        }

        // The parcel is already open and already holds its units, so you can
        // tell the carrier exactly what is in the box: put them on the
        // customs declaration, the packing list, wherever they belong.
        $consignment = Dahu_Serial_Numbers_Consignments::get( $consignment_id );
        $serials     = $consignment ? $consignment['serials'] : array();

        $label = my_carrier_api_create_label( array(
            'contents' => implode( ', ', $serials ),
            'weight'  => $order->get_meta( '_cart_weight' ),
            'name'    => $order->get_formatted_shipping_full_name(),
            'address' => $order->get_shipping_address_1(),
            'postcode'=> $order->get_shipping_postcode(),
            'city'    => $order->get_shipping_city(),
            'country' => $order->get_shipping_country(),
        ) );

        // Store the PDF wherever you like — this plugin does not want to know.
        $order->update_meta_data( '_my_carrier_label_url', $label['pdf_url'] );

        return $label['tracking_number'];
    },
    10,
    5
);

// 2. Build a link somebody can follow. Nothing is guessed by default: there is
//    no list of carrier URLs in this plugin, and a guessed one that 404s is
//    worse than none.
add_filter(
    'dahu_serial_numbers_tracking_url',
    function ( $url, $tracking, $carrier, $order, $consignment_id ) {
        // $order can be null when the link is built from a consignment alone.
        return 'https://my-carrier.example/track/' . rawurlencode( $tracking );
    },
    10,
    5
);
```

**What happens when the carrier's API is down**, because one morning it will
be. Throw, and the parcel is still recorded as shipped: every unit in it moves
to *shipped*, the order moves on, and the failure is written into the order
note, into each unit's history, and onto the packing screen so the person at
the bench sees it before they start the next parcel.

That is a deliberate choice, and the reasoning is that the two things at risk
are not worth the same. A tracking number lost is recoverable — somebody adds
it to the order this afternoon. The record of **which units went in which box**
is not: the box is taped shut and on a van, and the only person who knew is at
the next bench. Letting the exception through would mean a 500 on the packing
screen, a sealed parcel, and nothing on file — the plugin failing at the one
job it has because somebody else's server did not answer.

A shop where nothing may ship without a real label says so, and then nothing
moves at all:

```php
add_filter( 'dahu_serial_numbers_block_on_label_failure', '__return_true' );
```

## Consignments

A **consignment** is the set of units that left the shop — or came back —
together, under one reference: `C-2026-00042` going out, `R-2026-00007` coming
in. It answers the question nothing else could: *which units were in the parcel
with this tracking number?* Before, the carrier and tracking number were order
meta, one of each per order, and the link between a number and its units had to
be guessed from the history.

Closing a parcel now opens a consignment, puts the units in it, and closes it.
The order meta (carrier, tracking number, reference) is still written, as a
mirror of the most recent outbound parcel, for the theme, the e-mail or the
export that reads it.

**It is a ledger, not an actor.** The register moves no unit between statuses;
`Dahu_Serial_Numbers_Shipment` does that, and uses the register to write down
what it did. There is still no carrier API, no label, no manifest: the carrier
is a name you type and the tracking number a string you paste.

### Partial shipments

`Dahu_Serial_Numbers_Shipment::ship()` takes `$args['serials']` to ship only
some of an order, and `POST /orders/{id}/shipment` takes the same `serials`
argument. Omit it and the whole order ships exactly as before.

The completeness check — *every unit on the order has a serial number* — applies
to a whole-order shipment only. It does not apply to a partial one: an order
that leaves in two parcels necessarily has units without a serial while the
first goes out, and refusing that would make the only way to ship half an order
to lie about the other half. Every serial named must be assigned to the order,
or the call is refused. By default the order moves to *completed* only when
nothing is left to send; otherwise its status is left alone.

### The consignments screen

**Serial numbers → Consignments** in the admin. One search box takes a
reference, a tracking number or a serial number. It is read-only but for one
button: **cancel a consignment that is still open**. That is the way out when a
process died between moving the units and closing the parcel, which would
otherwise leave the units unable to join any other consignment — including the
one that records their return. A closed consignment can never be cancelled: the
parcel has gone.

### Reading the register over REST

| | |
|---|---|
| `GET /consignments` | a list; **requires at least one** of `order_id`, `tracking`, `reference`, `serial` |
| `GET /consignments/{reference}` | one consignment and its units |

The list refuses an unscoped request on purpose. The token that guards these
routes belongs to a workshop post — a tablet shared on a bench, not a person —
and an unnarrowed list would hand it the shop's whole shipping history. Packing
always has a context (an order, a label, a unit in your hand), so nothing
legitimate is lost.

Writing to the register is **not** exposed over REST, deliberately. A
carrier integration runs in the same PHP process and does not need a route, and
a route would let a bench token write arbitrary movement records.

### The tables are a public contract

`dahu_serial_numbers_consignments` and `dahu_serial_numbers_consignment_serials`
(with your table prefix) may be read by other plugins. Changes to them are
**additive only**. The version of the contract is
`Dahu_Serial_Numbers_Consignments::API_VERSION`, readable without loading the
class from the option `dahu_serial_numbers_consignments_api`, and returned by
`GET /consignments` as `api_version`. The database schema is at version 3.

### Two known limits

- **A cancelled consignment keeps its units.** Somebody did put them in that
  box, and the fact is part of the story. So every reader must look at `status`
  before counting departures; only `closed` ones happened.
- **"Already in another open consignment" is not enforced under concurrency.**
  The check is a read followed by an insert. Two benches closing two parcels in
  the same instant can both be told a unit is free and both record it. The
  status matrix catches it — `shipped` to `shipped` is refused, so the second
  movement fails and that unit comes back out of its consignment — but the
  guarantee is not the database's. A uniqueness table that closes the gap is
  additive and will come in a later release.

## Which products carry serial numbers

A checkbox on the product, under **Inventory**. On a variable product, ticking
it on the parent covers every variation that has no answer of its own.

A shop with four hundred serialised references does not tick four hundred
boxes:

```php
add_filter(
    'dahu_serial_numbers_is_serialised',
    function ( $serialised, $product_id ) {
        return $serialised || has_term( 'motors', 'product_cat', $product_id );
    },
    10,
    2
);
```

## Reading a date out of a serial number

Nothing is read by default, and that is the correct answer for a shop whose
serial numbers encode nothing. A plugin that guessed would fill a column with
wrong dates that look right.

Some suppliers do encode one. A scheme is shipped for the common shape — first
letter the year, second letter the month, over a twenty-six year cycle — and it
is hooked on rather than assumed:

```php
add_filter(
    'dahu_serial_numbers_decode',
    array( 'Dahu_Serial_Numbers_Format', 'letter_year_month' ),
    10,
    2
);
```

The cycle matters: `A` is 2001, and 2027, and 2053. The scheme picks the
occurrence nearest the current year, which is right because a unit being
received was made recently — and stays right as the years pass, which a table
of fixed years would not.

Any other encoding is a function of your own on the same filter, returning
`array( 'year' => …, 'month' => … )`. A year outside 1900–2200 or a month
outside 1–12 is dropped rather than written to the column.

## The API

Eleven routes under `dahu-serial-numbers/v1`, used by the packing screen. Every
one of them but sign-in requires a bearer token issued to a workshop post.

| | |
|---|---|
| `POST /auth/login` | login and password in, token out |
| `POST /auth/logout` | drops the token |
| `GET /orders` | the packing queue, or a search with `q` |
| `GET /orders/{id}` | one order, its lines and their serial numbers |
| `POST /orders/{id}/serials` | assign one serial number to one line |
| `DELETE /orders/{id}/serials/{serial}` | take it back off |
| `POST /orders/{id}/shipment` | close the parcel; optional `serials` ships only those units |
| `GET /consignments` | consignments matching `order_id`, `tracking`, `reference` or `serial` (one is required) |
| `GET /consignments/{reference}` | one consignment and its units |
| `GET /serials/{serial}` | one unit, with its history |
| `POST /serials/{serial}/checks` | record a physical check |

Sign-in is the one open route, and it has to be: an endpoint whose job is to
issue the credential cannot require the credential. What protects it is a
lockout — five failures on one login close it for ten minutes, which caps
guessing and also caps how much password hashing an unauthenticated caller can
make the server do.

That lockout is per login, so it does not stop one password being sprayed
across many logins. Keying on the caller's address instead would be worse than
useless here: every bench in a workshop shares one address, so one person
fumbling a password would lock out the whole floor.

Tokens are random strings. What is stored is a SHA-256 hash of one, never the
string itself, so a dump of the token table lets nobody sign in. There is no
JWT library: a signed token is useful when the signature has to be checked
somewhere the database is not reachable, which is never the case here.

## Hooks

**Filters**

| | |
|---|---|
| `dahu_serial_numbers_transitions` | widen the lifecycle |
| `dahu_serial_numbers_columns_cleared` | which columns a move clears |
| `dahu_serial_numbers_is_serialised` | which products carry a serial number |
| `dahu_serial_numbers_resolve_product` | redirect a product id |
| `dahu_serial_numbers_normalise` | the last word on what a serial looks like |
| `dahu_serial_numbers_url_parameters` | which query parameters a scanned URL may carry it in |
| `dahu_serial_numbers_decode` | read a manufacturing date out of a serial |
| `dahu_serial_numbers_queue_statuses` | which orders the packing queue lists |
| `dahu_serial_numbers_auth_provider` | replace the authentication backend |
| `dahu_serial_numbers_token_ttl` | how long a session lasts |
| `dahu_serial_numbers_auth_max_attempts` | failures before a login locks |
| `dahu_serial_numbers_auth_lockout_window` | how long it stays locked |
| `dahu_serial_numbers_before_shipment` | create a carrier label, return a tracking number — `( $tracking, $consignment_id, $order, $carrier, $station_id )` |
| `dahu_serial_numbers_block_on_label_failure` | refuse the parcel when the carrier fails, instead of recording it — 4th parameter `$consignment_id` |
| `dahu_serial_numbers_shipment_status` | where an order goes once shipped — 3rd parameter `$consignment_id`; the default is `completed` only when nothing is left to send, an empty string (status unchanged) otherwise |
| `dahu_serial_numbers_tracking_url` | build a link to follow a parcel — 5th parameter `$consignment_id`; `$order` (4th) can be `null` |

**Actions**

| | |
|---|---|
| `dahu_serial_numbers_created` | a unit was filed |
| `dahu_serial_numbers_transitioned` | a unit moved |
| `dahu_serial_numbers_shipped` | a parcel was closed — 5th parameter `$consignment_id` |
| `dahu_serial_numbers_consignment_opened` | a consignment was opened |
| `dahu_serial_numbers_consignment_closed` | a consignment was closed |
| `dahu_serial_numbers_consignment_cancelled` | a consignment was cancelled |
| `dahu_serial_numbers_station_credentials_revoked` | a post was switched off, or its password changed |

## Times

Every datetime this plugin stores is UTC, and is converted to the shop's
timezone at the moment it is shown. If you query the tables yourself, read them
as UTC.

## Uninstalling

The tables are kept unless you tick the box on the settings screen, and the box
is off.

That is not timidity. The serial numbers and their history are the only record
of which unit went to which customer, going back as far as you have been
serialising, and a warranty claim arrives two years later. Uninstalling is also,
most of the time, a step in reinstalling.

What always goes is what costs nothing to rebuild: the scheduled event, the
sign-in lockout counters, the stored schema version.

## External services

None. The plugin makes no outbound request of any kind.

## License

MIT. Hugo Vial-Jaime — Dahu-Concept.
