## Account and Workspace Contract Updates

These changes are under `/api/v1`. Protected routes require Sanctum authentication. Authentication and account route details not listed here remain in their existing auth flow documentation/source.

### Workspace sign-in lookup

`GET /workspaces/{slug}` is public and rate-limited (`throttle:public`). The slug is the subdomain prefix; it accepts letters, digits and hyphens. A found workspace returns:

```json
{
  "exists": true,
  "slug": "acme",
  "name": "Acme Foundation",
  "available": true,
  "status": "active",
  "branding": {
    "name": "Acme Foundation",
    "logo": null,
    "theme": null,
    "mode": null,
    "accent_color": null,
    "accent_style": null,
    "gradient": null
  }
}
```

Unknown/reserved slugs return `404` with `{ "exists":false,"message":"Workspace not found." }`. `available` is false only for a suspended workspace; `pending_verification` remains available for sign-in. Branding values may be null.

### Chat contact card

`GET /users/{id}/contact` is authenticated and returns a public-safe card (`id`, `fname`, `lname`, `image`, `role`, `affiliation`, `city`, `country`, `member_since`). It intentionally does not return email or phone. A missing/deleted user returns `404`. See [Communication](../communication/README.md).

### Profile route change

Profile updates now use `PATCH /profile/update/{id}` (previous `POST /profile/edit/{id}` is no longer routed). The handler updates the authenticated user's profile; accepted fields are optional: `first_name`, `last_name`, `display_name`, `country`, `city`, `website`, `dob`, `phone`, `gender`, and `image`. Text limits are 100 for names/location, 255 for website, 20 for phone; `website` must be a URL; `gender` is `Male`, `Female`, or `other`; image is JPG/JPEG/PNG/WEBP up to 2 MB. Success returns `{ "message":"Profile updated successfully." }`.

### External reviewer account conversion

`POST /account/upgrade-to-service-provider` requires authentication and converts the caller's external reviewer account (user type 6) into an independent service provider (type 3). Required fields: `gender` (`Male`, `Female`, `Other`), `dob` (date), and `supplier_type` (`material_goods`, `business_service`, `labor`). Optional fields: `display_name` (max 255), `phone` (max 50), `website` (URL, max 255), `country` (max 10), `city` (max 100), `image` (JPEG/PNG/JPG/GIF/SVG up to 2 MB), `bio` (max 1000), `region` (max 100), `service_areas` (array), `work_mode` (`remote`, `onsite`, `hybrid`; defaults to `hybrid`), `available_days` (array), `available_from`, `available_to` (max 10 each). Existing reviewer accounts with another user type or an existing provider profile receive `422`. This conversion removes the account's organization association and invitation metadata.
