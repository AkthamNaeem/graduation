# Dynamic Job Filters API

This endpoint is the backend-owned schema for the job Explore filters. Clients render the logical component types and send the declared parameters to the public jobs endpoint. Clients remain responsible for visual styling, icons, colors, typography, spacing, and platform-specific interaction details.

## Request

```http
GET /api/v1/reference/job-filters
Accept: application/json
Accept-Language: en
```

- Authentication: none.
- `Accept-Language`: supports `en` and `ar`, including regional values such as `en-US` and `ar-SY`.
- The response includes `Content-Language` and `Vary: Accept-Language`.

Arabic request:

```bash
curl -H "Accept: application/json" \
  -H "Accept-Language: ar" \
  http://localhost/api/v1/reference/job-filters
```

English request:

```bash
curl -H "Accept: application/json" \
  -H "Accept-Language: en" \
  http://localhost/api/v1/reference/job-filters
```

## Response contract

```json
{
  "success": true,
  "message": "Job filters retrieved successfully.",
  "data": {
    "schema_version": 1,
    "filters": [
      {
        "key": "city",
        "label": "City",
        "type": "single_select",
        "parameter": "city_id",
        "clearable": true,
        "default": null,
        "options": [
          {
            "key": 1,
            "value": "Damascus",
            "meta": { "code": "damascus" }
          }
        ]
      },
      {
        "key": "include_remote",
        "label": "Include remote jobs",
        "type": "boolean",
        "parameter": "include_remote",
        "default": false,
        "visible_when": {
          "parameter": "city_id",
          "operator": "has_value"
        }
      },
      {
        "key": "skill",
        "label": "Skill",
        "type": "autocomplete",
        "parameter": "skill",
        "clearable": true,
        "default": null,
        "options_source": {
          "type": "remote",
          "endpoint": "/api/v1/skills",
          "search_parameter": "search",
          "value_field": "slug",
          "label_field": "name",
          "minimum_search_length": 0
        }
      },
      {
        "key": "salary",
        "label": "Salary range",
        "type": "range",
        "parameters": {
          "minimum": "salary_min",
          "maximum": "salary_max"
        },
        "default": { "minimum": null, "maximum": null },
        "constraints": { "minimum": 0, "step": 1 }
      }
    ],
    "sort_options": [
      {
        "key": "newest",
        "value": "Newest",
        "parameters": {
          "sort_by": "published_at",
          "sort_direction": "desc"
        }
      }
    ]
  }
}
```

With `Accept-Language: ar`, stable keys and query parameter names do not change. Only human-readable values change, for example:

```json
{
  "message": "تم جلب فلاتر الوظائف بنجاح.",
  "data": {
    "filters": [
      {
        "key": "city",
        "label": "المدينة",
        "parameter": "city_id",
        "options": [
          {
            "key": 1,
            "value": "دمشق",
            "meta": { "code": "damascus" }
          }
        ]
      }
    ]
  }
}
```

The city options contain only active Syrian cities. The numeric option `key` is the value sent as `city_id`; `meta.code` is informational.

## Supported filter types

| Type | Client behavior |
| --- | --- |
| `single_select` | Select at most one item from the inline `options` array. |
| `boolean` | Send the declared boolean `parameter`. |
| `autocomplete` | Load choices from `options_source` and send the selected value. |
| `range` | Send zero, one, or both names declared in `parameters`, subject to `constraints`. |

Filters are returned in a stable order: city, include remote, work mode, employment type, experience level, skill, salary, and accepting applications. The schema intentionally does not expose legacy or technical inputs such as `location`, `city_code`, `skill_requirement`, `per_page`, or `page`.

## Building a jobs query

For a regular filter, use its `parameter`. For a range, use the values in `parameters`. For sorting, copy the selected sort option's complete `parameters` object.

Example:

```http
GET /api/v1/jobs?city_id=1&include_remote=true&work_mode=hybrid&employment_type=full_time&experience_level=mid_level&skill=laravel&salary_min=500&salary_max=2000&accepting_applications=true&sort_by=published_at&sort_direction=desc
```

Do not send translated labels as values. Enum option `key` values, city numeric keys, and remote skill slugs are the stable API values.

When `visible_when` is present, clients should display the filter only when the condition is met. In schema version 1, `has_value` means the referenced parameter is selected and non-null.

## Ownership boundary

The backend controls which filters exist, their order, logical component types, defaults, clearability, dependencies, selectable values, remote option sources, query parameter mapping, and supported Explore sort choices. The frontend controls how those logical components look and behave visually. The response deliberately contains no colors, icons, font sizes, or other visual styling.
