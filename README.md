# pixelperfectat/magento2-module-store-pickup

Order online, collect in store. The module adds a shipping carrier with one rate per pickup location, puts the
location address on the order, and gives the shop a "Ready for Pickup" step that ships the order, emails the
customer and sets the order status.

It is the core of the store pickup family: it works on its own with the standard Magento checkout request flow
(REST, admin-created orders and the server-side checkout path), and it has extension points for checkout
integrations and inventory packages.

## Requirements

- PHP `^8.3`
- Magento Open Source 2.4 or Mage-OS, with the Magento modules listed in `composer.json` (framework, backend,
  checkout, config, customer, directory, email, payment, quote, sales, shipping, store, ui).
- No Magento Inventory (MSI) module is required.

## Installation

```bash
composer require pixelperfectat/magento2-module-store-pickup
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` adds the `pp_pickup_location_code` and `pp_pickup_notified_at` columns and the order status
"Ready for Pickup".

## Configuration

Stores > Configuration > Sales > Delivery Methods > Store Pickup (`carriers/pppickup/*`) holds the carrier.
Stores > Configuration > Sales > Store Pickup (`pp_store_pickup/*`) holds the email and the payment rules.

| Path | Default | Scope |
|---|---|---|
| `carriers/pppickup/active` | `0` | website |
| `carriers/pppickup/title` | `Store Pickup` | store view |
| `carriers/pppickup/price` | `0` | website |
| `carriers/pppickup/sort_order` | `0` | website |
| `carriers/pppickup/sallowspecific` | `0` | website |
| `carriers/pppickup/specificcountry` | empty | website |
| `carriers/pppickup/showmethod` | `0` | website |
| `carriers/pppickup/specificerrmsg` | `This shipping method is not available.` | store view |
| `carriers/pppickup/locations` | empty | website |
| `carriers/pppickup/location_texts` | empty | store view |
| `pp_store_pickup/ready_email/enabled` | `1` | store view |
| `pp_store_pickup/ready_email/identity` | `sales` | store view |
| `pp_store_pickup/ready_email/template` | `pp_store_pickup_ready_email_template` | store view |
| `pp_store_pickup/ready_email/guest_template` | `pp_store_pickup_ready_email_guest_template` | store view |
| `pp_store_pickup/ready_email/copy_to` | empty | store view |
| `pp_store_pickup/ready_email/copy_method` | `bcc` | store view |
| `pp_store_pickup/payment/pickup_only_methods` | empty | website |
| `pp_store_pickup/payment/hidden_for_pickup` | empty | website |

The carrier is off until you enable it and add at least one location. The rates do not depend on the destination
address, so they survive the address swap described below. The country restriction (`sallowspecific`,
`specificcountry`) works as for any Magento carrier.

### Pickup locations

`carriers/pppickup/locations` is a dynamic-rows field at website scope, one row per location:

| Column | Meaning |
|---|---|
| Code | Permanent identifier, `^[a-z0-9_]{1,64}$`. It becomes the rate method (`pppickup_<code>`). |
| Name | The rate title the customer sees, and the `company` of the swapped shipping address. |
| Street | One street line per line. |
| Postcode, City, Country | Required. |
| Region ID, Region | Optional. |
| Telephone, Opening Hours, Note | Optional; shown in the ready email. |
| Price | Empty uses the default price (`carriers/pppickup/price`). |
| Sort Order | Ascending; ties are sorted by code. |
| Active | Inactive locations get no rate. |

Saving is refused when a code is invalid or repeated, or when name, street, postcode, city or country is empty.

### Store-view texts

`carriers/pppickup/location_texts` is a second dynamic-rows field, at store view scope, with the columns Code, Name,
Opening Hours and Note. A row replaces the name, opening hours or note of the location with the same code in that
store view. An empty cell keeps the website value, so a store view only needs the texts it translates.

### Code rules and immutability

The code is stored on the quote and the order, so it must stay stable. Renaming or deleting a code that active
carts or open orders (any state except complete, closed or canceled) still use is refused with an error that tells
you to deactivate the location instead. The guard also applies when an admin reverts a website or store view scope
to "Use default" and that would drop such a code. Name, address, hours and note can change freely.

The usage check is global: it counts carts and orders of all websites, not only the website you are editing.

## What the address swap writes

When a pickup rate is the selected shipping method, the module replaces the quote shipping address with the
location address, so emails, the order grid, invoices and exports show it without further code:

- street, postcode, city, country and region come from the location;
- `company` is set to the location name; the customer's company stays on the billing address;
- first name, last name and telephone stay the customer's (taken from billing, then from the customer, when empty);
- `same_as_billing`, `save_in_address_book` and `customer_address_id` are reset, and the address is tied to the
  customer id, so a logged-in customer never gets the shop address saved into the address book;
- the address the customer had before is kept in a snapshot and restored when the customer switches back to a
  delivery method.

The swap runs in three places: a plugin before `QuoteManagement::submit` (storefront, REST, admin-created
orders), a plugin before `ShippingInformationManagement::saveAddressInformation` (the server-side checkout path),
and an observer when the checkout page opens. A checkout integration can call
`PickupAddressApplierInterface::apply()` and `revert()` itself as soon as the customer picks or leaves a pickup rate.

The location code is stored in `quote.pp_pickup_location_code`, `sales_order.pp_pickup_location_code` and
`sales_order_grid.pp_pickup_location_code`, and exposed as the extension attribute `pp_pickup_location_code` on the
cart and the order. The order grid gets a filterable column.

## Ready for pickup

The order view gets a "Ready for Pickup" button for pickup orders in state new, processing or complete that were not
marked yet, and the order grid gets a mass action. Both need the ACL resource `PixelPerfect_StorePickup::ready_for_pickup`.
One click runs the whole step:

1. creates the shipment, when the order has none;
2. sets the order status `ready_for_pickup`, assigned to the state `processing` and visible on the storefront;
3. sends the translatable "Ready for Pickup" email (customer and guest template, optional copy);
4. adds a customer-visible history comment, written in the locale of the order's store;
5. records `pp_pickup_notified_at`.

A prepaid order, that is an invoiced one, becomes `complete` when the shipment is created, and keeps that Magento
status; the email, the comment and the timestamp still follow. If the email cannot be sent the order is still
marked, the history comment is flagged as not sent to the customer, and the admin sees a warning. An order is marked at
most once.

### Integration point for imports

`PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface` is the public contract of the step, for
modules that must trigger it, for example when an import reports that the order was processed elsewhere:

```php
if ($service->isEligible($order)) {
    $service->execute((int) $order->getEntityId());
}
```

`execute()` throws `AlreadyNotifiedException` when the order was already marked, and a `LocalizedException` when the
order is no pickup order, is in another state, or its location no longer exists. The module never reacts to
shipments created elsewhere (import, API, admin shipment form): such a shipment sends no ready email by itself.

## Payment restriction

Two optional multiselects under Sales > Store Pickup > Payment Methods for Pickup:

- "Payment Methods Only for Pickup" are offered for pickup orders only (for example pay at the store);
- "Payment Methods Hidden for Pickup" are not offered for pickup orders.

Both are empty by default, so nothing changes until you configure them. The rule listens to
`payment_method_is_active` and decides by the shipping method stored on the quote's shipping address. A checkout
that offers payment methods before it stores the selected pickup method on the quote shows the unrestricted list
until it does.

## Extension points

Every service has an interface in `Api/Service` with a `<preference>`. The four composites below take named
members in `di.xml`. Use `sortOrder` on the items to control the order.

### Location providers

`LocationProviderInterface` supplies the locations. The composite merges all providers; on a duplicate code the
first provider wins and the clash is logged. The `config` provider reads the admin fields.

```xml
<type name="PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="warehouses" xsi:type="object" sortOrder="20">Vendor\Module\Service\WarehouseLocationProvider</item>
        </argument>
    </arguments>
</type>
```

### Availability checkers

`AvailabilityCheckerInterface::isAvailable($location, $rateRequest)` decides whether a location gets a rate for the
cart. Every checker must say yes.

```xml
<type name="PixelPerfect\StorePickup\Service\Availability\CompositeAvailabilityChecker">
    <arguments>
        <argument name="checkers" xsi:type="array">
            <item name="salable" xsi:type="object" sortOrder="20">Vendor\Module\Service\SalableAtLocation</item>
        </argument>
    </arguments>
</type>
```

### Order placement processors

`OrderPlacementProcessorInterface::process($order, $location)` runs once after a pickup order is placed, for
example to copy the location to an inventory source. Exceptions are logged and never reach the customer.

```xml
<type name="PixelPerfect\StorePickup\Service\OrderPlacement\CompositeOrderPlacementProcessor">
    <arguments>
        <argument name="processors" xsi:type="array">
            <item name="source_copy" xsi:type="object">Vendor\Module\Service\CopyLocationToSource</item>
        </argument>
    </arguments>
</type>
```

### Shipment arguments

`ShipmentArgumentsBuilderInterface::build($order, $location)` returns the creation arguments of the shipment
that the ready-for-pickup step creates. The default returns empty arguments. This is not a composite: an inventory
integration replaces it with a `<preference>`, or wraps it with an after plugin.

```xml
<preference for="PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface"
            type="Vendor\Module\Service\SourceShipmentArgumentsBuilder"/>
```

## Logging

Everything the module logs goes to the dedicated channel `store_pickup` (`var/log/store_pickup.log`).

## Translations

`en_US`, `de_DE` and `it_IT` ship with identical keys; a unit test keeps them in sync.

## Known limits

- No store locator, no maps, no pickup time slots and no GraphQL.
- The location code usage guard is global across websites.
- The "pickup only" and "hidden" payment lists rely on the shipping method stored on the quote.
- The module does not ship a checkout UI. The server-side swap works everywhere; a checkout that hides the
  address form needs a companion or its own calls to `PickupAddressApplierInterface`.
- Ready for pickup is triggered only by the button, the mass action and `ReadyForPickupServiceInterface`.

## Compatibility

Checked against the Magento Open Source `2.4.7-p10` source (tag `2.4.7-p10` of `magento/magento2`). The design relies on the following behaviour:

| Behaviour | Result | Location (2.4.7-p10) |
|---|---|---|
| `submitQuote` validates the quote before any address conversion | confirmed: `validateQuote` runs first, the quote addresses are converted afterwards | `Magento/Quote/Model/QuoteManagement.php:565` (validate), `:587` and `:599` (conversion) |
| The order is validated before `sales_model_service_quote_submit_before` | confirmed, with one nuance: `validateOrder` runs after the address conversion, directly before the event | `QuoteManagement.php:626` (`validateOrder`), `:628` (event) |
| `_prepareCustomerQuote` exports the shipping address when `!same_as_billing && (!customer_id \|\| save_in_address_book)` | confirmed | `QuoteManagement.php:672-673` |
| `Order::getShippingMethod(true)` splits with `explode('_', ..., 2)` | confirmed | `Magento/Sales/Model/Order.php:1427` |
| `quote_address.shipping_method` is `varchar(120)` | confirmed | `Magento/Quote/etc/db_schema.xml:142` |
| `sales_order.shipping_method` is `varchar(120)` | confirmed | `Magento/Sales/etc/db_schema.xml:220` |
| `payment_method_is_active` is dispatched by the payment method base classes | confirmed for both | `Magento/Payment/Model/Method/AbstractMethod.php:788`, `Magento/Payment/Model/Method/Adapter.php:307` |

The lab runs Mage-OS 3.1.0, which tracks the same code line.

## License

MIT
