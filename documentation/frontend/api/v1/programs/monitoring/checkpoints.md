## Monitoring Checkpoints

`GET|POST /monitoring/applications/{app}/checkpoints`, `PATCH|DELETE /monitoring/checkpoints/{checkpoint}`. Owners define and maintain checkpoints; request data includes name, type, due date, and reporting requirements. `checkpoint_description` is optional on create and can be updated with `PATCH /monitoring/checkpoints/{checkpoint}` as a nullable string up to 500 characters.
