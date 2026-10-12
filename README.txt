=== Dahu Serial Numbers ===
Contributors: hugovialjaime
Tags: woocommerce, serial numbers, traceability, warranty, inventory
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
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

Eleven statuses — received, in stock, assigned to an order, shipped, return
expected, returned as described, returned not as described, in repair,
replaced, going back to stock, destroyed — and a matrix that says which moves
are legal. Every status change goes through one check, and there is no flag
anywhere that turns it off.

A unit put back in stock is a free unit: the order, the order line, the service
ticket and the replacement pointer are all cleared. Nothing is lost — what was
cleared is written into the history line.

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

= 1.1.0 =
* Breaking: the filter dahu_serial_numbers_before_shipment now receives ( $tracking, $consignment_id, $order, $carrier, $station_id ); the consignment id is the new second parameter.
* New: consignment register recording which serial numbers left, or came back, together, under which reference.
* New: partial shipments, by passing a list of serial numbers to ship part of an order.
* New: Consignments screen in the admin, and read-only REST routes GET /consignments and GET /consignments/{reference}.
* New: actions dahu_serial_numbers_consignment_opened, _closed and _cancelled.
* Changed: dahu_serial_numbers_block_on_label_failure, dahu_serial_numbers_shipment_status, dahu_serial_numbers_tracking_url and dahu_serial_numbers_shipped each gain a trailing $consignment_id parameter; the tracking URL filter's order can be null.
* Changed: the default order status after a shipment is completed only when nothing is left to send; otherwise the status is left alone.
* Database schema is now version 3.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
If you have a listener on dahu_serial_numbers_before_shipment, update it before anything else: it now receives ( $tracking, $consignment_id, $order, $carrier, $station_id ) and must be registered with 5 accepted arguments, because the old signature hands your code an integer where it expects the order. The other changed hooks only gain trailing parameters. Two database tables for the consignment register are created on update.

= 1.0.0 =
First release.
