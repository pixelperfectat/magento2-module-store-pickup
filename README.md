# pixelperfectat/magento2-module-store-pickup

Order online, collect in store: one shipping rate per pickup location, the location address on the order, and a "ready for pickup" step with shipment and email.

Work in progress, not released yet.

## Requirements

- PHP `^8.3`
- Magento Open Source 2.4 or Mage-OS, with the Magento modules listed in `composer.json` (framework, backend, checkout, config, customer, directory, email, payment, quote, sales, shipping, store, ui). No Magento Inventory (MSI) module is required.

## Installation

```bash
composer require pixelperfectat/magento2-module-store-pickup
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

- Stores > Configuration > Sales > Delivery Methods > Store Pickup (`carriers/pppickup/*`): master toggle, title, default price, country restriction, error message.
- Stores > Configuration > Sales > Store Pickup (`pp_store_pickup/*`): ready-for-pickup email and payment method rules (filled by later tasks).

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
| `pp_store_pickup/ready_email/enabled` | `1` | store view |
| `pp_store_pickup/ready_email/identity` | `sales` | store view |
| `pp_store_pickup/ready_email/template` | `pp_store_pickup_ready_email_template` | store view |
| `pp_store_pickup/ready_email/guest_template` | `pp_store_pickup_ready_email_guest_template` | store view |
| `pp_store_pickup/ready_email/copy_to` | empty | store view |
| `pp_store_pickup/ready_email/copy_method` | `bcc` | store view |
| `pp_store_pickup/payment/pickup_only_methods` | empty | website |
| `pp_store_pickup/payment/hidden_for_pickup` | empty | website |

## Logging

Everything the module logs goes to the dedicated channel `store_pickup` (`var/log/store_pickup.log`).

## Translations

`en_US`, `de_DE` and `it_IT` ship with identical keys; a unit test keeps them in sync.

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
