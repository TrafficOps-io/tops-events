# Event catalog

Shared field discovery, event schemas, payload validation and routing suggestions for PHP/Laravel applications. The package has no models, migrations, routes or global catalog state.

## Responsibilities

- `PayloadFields` discovers at most 100 body/query/header fields per request. Source order, reserved control keys, empty-array discovery and support for dotted header names are configurable. It visits nested objects and indexed arrays without retaining values, rejects unaddressable/overlong paths and stops walking once the limit is reached. Merging preserves existing required and exact-value rules, canonicalizes header identity and never removes configured rules to meet a discovery limit.
- `FieldSchema` supplies common Laravel validation rules for schema editors and APIs: field sources, paths, required flags, optional types and exact values. Applications add row IDs, persistence rules and their own allowed sources.
- `FieldValidator` applies required, type and exact-value constraints. Exact matching preserves scalar types, treats integer/float equivalents as equal and never includes configured secrets in errors. Missing optional fields pass. Header lookup explicitly supports literal names (including dots) or traversal through the raw header array, such as `x-id.0`, before single-value unwrapping.
- `EventNameResolver` handles path/query/body precedence, unnamed events, maximum length, optional parameter-name selectors and reserved system event names.
- `EventCatalog` stores only event names, kinds and field metadata. Custom and system events have separate namespaces. Selected-event suggestions merge shared paths, event associations and conflicting types; consumer options supply macro prefixes and header-array suffixes.
- `FieldSuggestions` combines typed fields and bounded sample observations for system-event payloads and installation context. Its output contains paths, types and event names only.

## Using a catalog

```php
use TrafficOps\EventCatalog\EventCatalog;

$catalog = new EventCatalog;
// Query through the authorized gateway/campaign relationship in the application.
foreach ($campaign->eventDefinitions()->with('fields')->get() as $definition) {
    $catalog->add($definition->name, $definition->fields->toArray());
}
$catalog->add('offer.opened', [
    ['source' => 'body', 'path' => 'url', 'type' => 'string'],
], kind: 'system');

$customFields = $catalog->suggestions(['purchase'], headerSuffix: '.0');
$systemFields = $catalog->suggestions(['offer.opened'], kind: 'system');
```

Build a separate catalog for each authorized owner. The package intentionally does not query a database or grant access: adapters retain owner scoping, authentication, persistence, transaction locks, discovery enablement and sample retention/redaction. Pass already-redacted samples to observation methods. Do not include payload values in event names or field names.

## Compatibility policies

The applications retain their existing inputs and editor output shapes:

| Behavior | webhooks-gateway | pwapps |
| --- | --- | --- |
| Event name | `whg_event`, optional `whg_event_param`, maximum 255 | `event_name`, maximum 100, reserved system names |
| Discovery order | body, query, headers | query, body, headers |
| Empty array discovery | omitted | included |
| Required field | Laravel `required` | missing, null and empty string rejected |
| Header lookup | literal, case-insensitive names | dot paths through raw header arrays |
| Header validation | first header value | one value becomes scalar; multiple remain an array |
| Header discovery | letters, numbers, hyphens and underscores | dotted names also retained |
| Schema constraints | required and optional exact value | required and scalar type |
| Suggested payload prefix | `incoming.` | existing payload namespace |
| Suggested header suffix | first value resolved by renderer | `.0` for configured fields |

The pwapps adapter selects `laravelRequired: false, preserveMultipleHeaders: true, literalHeaderNames: false`; other consumers use defaults or choose their policies explicitly. Adding another payload source does not require app-specific code in this package: use discovery source options, schema source options and catalog source metadata. Installation fields and available system event names remain application-owned.

## Checks

From the repository root:

```sh
composer install
composer check
```

Tests cover discovery limits and merging, required/type/exact validation, control-name precedence, literal and repeated headers, custom/system selection, type conflicts, owner isolation and metadata-only output. Application integration suites verify database scoping, editor behavior and routing compatibility.
