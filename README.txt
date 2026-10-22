=== Dahu Serial Numbers ===
Contributors: hugovialjaime
Tags: woocommerce, serial numbers, traceability, warranty, inventory
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Per-unit traceability for WooCommerce: a serial number on every unit you ship, a lifecycle with real rules, a full history, and a packing screen for the bench.

== Description ==

Two questions, asked months apart.

A customer calls about a part that has failed. Which one did they get, which
batch was it from, and how many others from that batch went out? Without serial
numbers the answer is the order date and a guess.

A batch turns out to be faulty. Which customers have one, and which units are
still on the shelf? Without serial numbers the answer is everybody who bought
that product, which is both too many people to write to and not the right ones.

Dahu Serial Numbers answers both, because every unit has a row and every row
has a history.

= The lifecycle =

Thirteen statuses — received, in stock, assigned to an order, shipped, return
expected, returned as described, returned not as described, in repair,
repaired, replaced, going back to stock, held not counted as stock, destroyed —
and a matrix that says which moves are legal. Every status change goes through one check, and there is
no flag anywhere that turns it off.

A unit that came back not as described may be repaired, replaced or destroyed.
It may not go straight back on the shelf: that one really would be a guess.

A unit put back in stock is a free unit: the order, the order line, the service
ticket and the replacement pointer are all cleared. Nothing is lost — what was
cleared is written into the history line.

= WooCommerce stock =

Off until you switch it on. With it on, the stock of a serialised product
becomes the number of units in stock on no order, less the pieces orders being
prepared have already reserved — written as a whole number, never as an
increment. Variable parents, products whose stock WooCommerce does not manage
and products not marked as serialised are left alone and named on the Stock
screen. A target below zero is written as zero and the shortfall is named. The
Stock screen shows every product and what would be written before anything is,
and its manual resynchronisation works whether the automatic push is on or off.

= When a box went in under the wrong product =

Units in stock can be re-filed under another product: a dry run first, a reason
required, all of the list or none of it, in a transaction, and undoable as long
as nothing else has happened to the units since. Off until you switch it on,
and the dry run works either way. A unit on an order, or not in stock, is
refused: its product is what that order or that case says it is.

= The packing screen =

A page of its own, off until you switch it on, built for a tablet on a
workbench. It signs in with one login per bench rather than one per person: a
packer does not need a WordPress account to scan a number, and giving them one
means giving them the admin.

It lists the orders waiting, takes one scan at a time and checks it against the
shop as it happens, refuses a line more units than it ordered, and will not
close a parcel while a unit on it is unaccounted for.

A barcode reader is a keyboard, and many suppliers encode a URL on the label
rather than the number. The whole address lands in the field, and the plugin
reduces it to the serial.

= Shipping =

The plugin closes a parcel: every unit is recorded as having left, the carrier
and tracking number go on the order, and the order moves on. It does not talk
to a carrier — no label creation, no API credentials — because that needs an
account and a configuration particular to one shop. A carrier integration plugs
in with two filters and no changes to this plugin. The filter that creates the label receives the consignment, so the integration knows which units it is labelling.

= Consignments =

A consignment is the set of units that left the shop, or came back, together,
under one reference: C-2026-00042 going out, R-2026-00007 coming in. It answers
the question support is actually asked: which units were in the parcel with this
tracking number?

* Partial shipments: ship part of an order in one parcel and the rest in another, each with its own tracking number. The completeness check applies to a whole-order shipment only, because an order leaving in two parcels necessarily has units without a serial while the first one goes.
* A "Consignments" screen in the admin: search by reference, tracking number or serial number in one box. Its only action is cancelling a consignment left open by an interrupted process; a closed consignment can never be cancelled.
* Two read-only REST routes, which require at least one of order, tracking number, reference or serial number, so that a shared bench token cannot list the whole shipping history. Writing to the register is deliberately not exposed over REST.
* Two database tables that other plugins may read. Changes to them are additive only, and the contract version is in the option dahu_serial_numbers_consignments_api.

The register records what was shipped; it moves no unit itself and talks to no carrier. Two known limits: a cancelled consignment keeps its units, so check its status before counting departures; and "already in another open consignment" is not enforced when two benches act in the same instant, where the status rules stop the second movement instead.

= Reading a date out of a serial number =

Nothing is read by default, which is the correct answer for a shop whose serial
numbers encode nothing. A scheme for the common shape — first letter the year,
second letter the month, over a twenty-six year cycle — ships with the plugin
and is enabled with one line of code.

= Times =

Every datetime this plugin stores is UTC, converted to the shop's timezone when
it is shown.

= External services =

This plugin does not contact any external service. It makes no outbound request
of any kind, and ships no third-party library.

== Installation ==

1. Install and activate the plugin. Six database tables are created.
2. Tick "Serial numbers" under Inventory on the products that carry one.
3. Create a workshop post under Serial numbers → Workshop posts.
4. Switch the packing screen on under Serial numbers → Settings, and open
   /packing/ on the bench tablet.

== Frequently Asked Questions ==

= Does it need WooCommerce? =

Yes, 7.0 or later. It declares compatibility with High-Performance Order
Storage and works with either order store.

= Does it send anything anywhere? =

No. The plugin makes no outbound request of any kind.

= What happens to my data when I delete the plugin? =

Nothing, unless you ticked the box on the settings screen. The serial numbers
and their history are the only record of which unit went to which customer, and
uninstalling is most often a step in reinstalling.

= Can I import serial numbers I already have? =

Through code, yes: `Dahu_Serial_Numbers_Manager::create()` takes a serial, a
supplier batch, a product and an optional date, and accepts an explicit history
date so an import records when things really happened rather than the day of
the import. There is no import screen in this version.

= Why is the packing screen not in the admin? =

Because the person using it is not an administrator and should not have to be.
It signs in with a credential for the bench, not for a person, and the history
records which bench did what.

== Screenshots ==

1. The serial numbers list, with a tally by status.
2. One unit, and everywhere it has been.
3. Workshop posts.
4. The packing screen on a bench tablet.

== Changelog ==

= 1.3.0 =
* New: WooCommerce stock can be set from the units actually on the shelf — free units in stock, less the pieces orders being prepared have already reserved. Off at installation. Written as a value and never as an increment, never below zero, and never blocking the import, scan or order that triggered it.
* New: Stock screen, showing every serialised product, what WooCommerce says now and what would be written, with a manual resynchronisation that works whether the automatic push is on or off and refuses a summary that has since moved.
* New: units in stock can be re-filed under another product, for the box that went on the shelf under the wrong reference. Dry run, reason required, all-or-nothing in a transaction, and undoable while the correction is still the last thing that happened to the unit. Off at installation.
* New: a thirteenth status, held not counted as stock, for a unit the shop still has and has decided not to count. Its only exit is back to stock, and no REST route can reach it.
* New: filters dahu_serial_numbers_stock_reserving_statuses and dahu_serial_numbers_stock_reserved_map; action dahu_serial_numbers_product_changed.
* Unchanged: the database schema, and the consignment contract version, which stays at 1. Neither a status nor these two features changes a table.

= 1.2.0 =
* New: a repaired status, for the commonest outcome of a repair. Before it, the only way out of in repair was replaced, which says the customer got a different unit.
* Fixed: returned not as described was a dead end. A unit that came back faulty could not be moved on at all, and because a unit in an open consignment is refused by every other consignment, it could not even be recorded as going back out. It now leads to in repair, replaced or destroyed.
* New: in repair now also leads to repaired and to destroyed. Repaired leads back to the order the unit came from, to going back to stock, back to in repair when a unit fails its final check, or to destroyed.
* Fixed: no order line ships more units than it ordered. Shipping collected every assigned unit of an order with no quantity check, so a quantity-one line whose replacement had already gone out could ship a second unit — still on the bench — and record it as being with the customer. The whole parcel is now refused, naming the line, rather than trimmed.
* New: the serial numbers screen can move one unit to another status, offering only the moves the lifecycle allows from where that unit is, guarded by capability and nonce, and recorded in the unit's history.
* Nothing was removed from the transition matrix. Every move that was legal in 1.1.0 is still legal, including returned as described to in repair.
* Unchanged: the database schema, and the consignment contract version, which stays at 1. Adding a status changes neither.

= 1.1.0 =
* Breaking: the filter dahu_serial_numbers_before_shipment now receives ( $tracking, $consignment_id, $order, $carrier, $station_id ); the consignment id is the new second parameter.
* New: consignment register recording which serial numbers left, or came back, together, under which reference.
* New: partial shipments, by passing a list of serial numbers to ship part of an order.
* New: Consignments screen in the admin, and read-only REST routes GET /consignments and GET /consignments/{reference}.
* New: actions dahu_serial_numbers_consignment_opened, _closed and _cancelled.
* Changed: dahu_serial_numbers_block_on_label_failure, dahu_serial_numbers_shipment_status, dahu_serial_numbers_tracking_url and dahu_serial_numbers_shipped each gain a trailing $consignment_id parameter; the tracking URL filter's order can be null.
* Changed: the default order status after a shipment is completed only when nothing is left to send; otherwise the status is left alone.
* New: Shipment::amend_tracking() adds a carrier and tracking number to a closed consignment and fires dahu_serial_numbers_consignment_tracking_amended; it changes nothing else, and keeps the order meta mirror consistent.
* New: a label_error column on consignments, and a label_error argument to query() to list the parcels that left without a label.
* New: filters dahu_serial_numbers_consignment_row_actions (actions on a Consignments screen line) and dahu_serial_numbers_label_failure_note (the order note line written when the carrier failed).
* Database schema is now version 5.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.3.0 =
Nothing to do on update: no schema change, no hook signature change, and both new features are off until you switch them on in Settings. ONE THING TO READ BEFORE SWITCHING ON the stock push: it writes WooCommerce's stock column, which is what customers buy against, and it writes it as a whole number. Open the Stock screen first — it shows what would be written for every product, writes nothing, and names the products it will not touch. If your own code reads the status list or the terminal statuses, note that there are now thirteen statuses.

= 1.2.0 =
Additive to the lifecycle, and nothing to do on update: no schema change and no hook signature change. Units sitting in "returned not as described" from an earlier version were stuck there — that was the defect this release fixes — and they can now be moved on from the serial numbers screen, which gained a status control for exactly that. ONE BEHAVIOUR CHANGED, and it is a refusal: a shipment that would send more units than an order line ordered is now refused rather than carried out. If you have deliberately assigned more units to a line than it ordered, that shipment will stop and name the line; un-assign what is not going. If your own code reads the status list or the terminal statuses, note that there are now twelve statuses and that "returned not as described" is no longer terminal.

= 1.1.0 =
If you have a listener on dahu_serial_numbers_before_shipment, update it before anything else: it now receives ( $tracking, $consignment_id, $order, $carrier, $station_id ) and must be registered with 5 accepted arguments, because the old signature hands your code an integer where it expects the order. The other changed hooks only gain trailing parameters. Two database tables for the consignment register are created on update.

= 1.0.0 =
First release.
