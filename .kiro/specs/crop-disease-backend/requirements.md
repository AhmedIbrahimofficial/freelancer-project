# Requirements Document

## Introduction

The Crop Disease Diagnosis App Backend is a Python (FastAPI) + MySQL system that enables smallholder farmers to photograph their crops, receive AI-powered disease diagnoses, access structured treatment plans, locate nearby input suppliers, manage offline scan queues, and receive push notification reminders — all with multi-language support. The backend exposes a versioned REST API consumed by a mobile client, runs ML inference via a fine-tuned MobileNetV2 model (server-side first, on-device TFLite later), and processes async work through Celery + Redis.

## Glossary

- **API**: The FastAPI-based HTTP interface exposed at `/api/v1/...`
- **Scan**: A single submitted crop photo and its associated diagnosis lifecycle
- **Diagnosis**: The ML inference result attached to a Scan, including detected disease and confidence score
- **Treatment**: A structured remediation plan for a diagnosed disease, composed of ordered steps
- **Treatment_Step**: A single actionable instruction within a Treatment, with an associated day offset
- **Supplier**: A local agricultural input retailer, identified by geographic coordinates and stock
- **Sync_Queue**: A client-side record of actions performed while offline that must be replayed on reconnect
- **OTP**: A one-time password delivered via SMS for phone-based authentication
- **JWT**: A JSON Web Token used to authenticate subsequent API requests after OTP verification
- **Celery_Worker**: An async background task processor backed by Redis as the message broker
- **ML_Model**: The trained image classification model (MobileNetV2 fine-tuned on PlantVillage) used for inference
- **Model_Version**: A string identifier stored on each Diagnosis row indicating which ML_Model produced it
- **FCM**: Firebase Cloud Messaging, used for push notifications to mobile clients
- **S3_Storage**: An S3-compatible object store (AWS S3 or DigitalOcean Spaces) used for uploaded crop images
- **Translation**: A row in the `translations` table providing a localised string for a specific entity and language
- **Tip**: A seasonal advisory piece of content (photo or video) associated with a crop and region
- **Region**: A geographic identifier (e.g., country or province code) used to filter tips, suppliers, and users
- **PlantVillage**: The open dataset used to fine-tune the crop disease classification model

---

## Requirements

### Requirement 1: Phone-Based User Registration and OTP Verification

**User Story:** As a farmer, I want to register using my phone number and verify it with an OTP, so that I can access the app without needing an email address or password.

#### Acceptance Criteria

1. WHEN a `POST /api/v1/auth/register` request is received with a valid phone number and preferred language, THE API SHALL create a user record and trigger an OTP delivery via Twilio to that phone number within 5 seconds.
2. WHEN a phone number is submitted for registration that already exists in the `users` table, THE API SHALL return an HTTP 409 response with a descriptive error message.
3. WHEN a `POST /api/v1/auth/verify-otp` request is received with a matching, unexpired OTP, THE API SHALL return a signed JWT valid for 30 days.
4. IF the submitted OTP has expired or does not match the stored value, THEN THE API SHALL return an HTTP 401 response with an error code of `otp_invalid`.
5. WHEN a `POST /api/v1/auth/login` request is received for an existing verified phone number, THE API SHALL trigger a new OTP delivery and return HTTP 200.
6. THE API SHALL store passwords using bcrypt with a minimum cost factor of 12 wherever a password field is persisted.

---

### Requirement 2: Authenticated Session Management via JWT

**User Story:** As a registered user, I want my session to be maintained via a JWT, so that I can make authenticated API calls without re-entering my credentials on every request.

#### Acceptance Criteria

1. THE API SHALL validate the JWT signature on every protected endpoint before processing the request.
2. IF a request to a protected endpoint arrives without a valid JWT, THEN THE API SHALL return HTTP 401 with error code `unauthorized`.
3. WHEN a JWT expires, THE API SHALL return HTTP 401 with error code `token_expired` so the client can trigger re-authentication.
4. THE JWT SHALL encode the `user_id`, `phone_number`, and `exp` (expiry) claims using the HS256 algorithm.

---

### Requirement 3: Crop Photo Upload and Scan Record Creation

**User Story:** As a farmer, I want to upload a photo of my crop and have a scan record created, so that I can receive a disease diagnosis.

#### Acceptance Criteria

1. WHEN a `POST /api/v1/scans` request is received with a valid image file, THE API SHALL validate that the file is JPEG or PNG format and does not exceed 10 MB.
2. IF an uploaded file exceeds 10 MB or is not JPEG or PNG, THEN THE API SHALL return HTTP 422 with a descriptive validation error.
3. WHEN a valid image is received, THE API SHALL compress the image to a maximum dimension of 1024×1024 pixels while preserving aspect ratio before uploading to S3_Storage.
4. WHEN the image is stored, THE API SHALL create a `scans` row with status `pending` and enqueue a `run_diagnosis` Celery task, returning HTTP 202 with the scan `id`.
5. WHEN a scan is created while the user is marked as offline, THE API SHALL set the scan status to `queued_offline` instead of `pending`.
6. THE API SHALL associate every scan with the authenticated user's `user_id`.

---

### Requirement 4: Asynchronous ML Inference and Diagnosis

**User Story:** As a farmer, I want the system to automatically analyse my photo and return a diagnosis, so that I can act on the results without waiting inline.

#### Acceptance Criteria

1. WHEN the `run_diagnosis` Celery task is executed, THE Celery_Worker SHALL load the image from S3_Storage, run inference using the ML_Model, and write a `diagnoses` row with `disease_id`, `confidence_score`, and `model_version`.
2. WHEN inference completes successfully, THE Celery_Worker SHALL update the `scans` row status to `completed`.
3. IF inference fails due to an unrecoverable error, THEN THE Celery_Worker SHALL update the `scans` row status to `failed` and log the error with the `scan_id`.
4. WHEN a diagnosis is written, THE Celery_Worker SHALL send an FCM push notification to the user's registered device containing the disease name and confidence score.
5. THE ML_Model SHALL accept an RGB image tensor of shape `(1, 224, 224, 3)` and return a probability distribution over all known disease classes.
6. THE Celery_Worker SHALL store the `model_version` string on every `diagnoses` row so that results can be traced back to a specific model release.

---

### Requirement 5: Scan Status and History Retrieval

**User Story:** As a farmer, I want to check the status of my scan and view my diagnosis history, so that I can track all past disease events on my farm.

#### Acceptance Criteria

1. WHEN a `GET /api/v1/scans/{id}` request is received for a scan that belongs to the authenticated user, THE API SHALL return the scan record including status, `created_at`, and the associated diagnosis if status is `completed`.
2. IF a `GET /api/v1/scans/{id}` request is made for a scan that does not belong to the authenticated user, THEN THE API SHALL return HTTP 403.
3. WHEN a `GET /api/v1/scans` request is received, THE API SHALL return a paginated list of the authenticated user's scans ordered by `created_at` descending, with a default page size of 20.
4. THE API SHALL support `page` and `page_size` query parameters on `GET /api/v1/scans`, with `page_size` capped at 100.

---

### Requirement 6: Treatment Plan Retrieval

**User Story:** As a farmer, I want to view a step-by-step treatment plan for my diagnosed disease, so that I can know exactly what actions to take in the field.

#### Acceptance Criteria

1. WHEN a `GET /api/v1/treatments/{disease_id}` request is received, THE API SHALL return the treatment record along with all associated `treatment_steps` ordered by `step_number` ascending.
2. IF no treatment exists for the given `disease_id`, THEN THE API SHALL return HTTP 404 with error code `treatment_not_found`.
3. THE API SHALL include the `days_offset` field on each `treatment_step` so the client can calculate the recommended calendar date for each step.

---

### Requirement 7: Field Checklist — Marking Treatment Steps Complete

**User Story:** As a farmer, I want to mark individual treatment steps as done, so that I can track my progress through a treatment plan.

#### Acceptance Criteria

1. WHEN a `POST /api/v1/treatment-steps/{id}/complete` request is received for a valid step, THE API SHALL create or update a `user_treatment_progress` row with `completed_at` set to the current UTC timestamp.
2. WHEN a step that has already been marked complete is submitted again, THE API SHALL return HTTP 200 and leave the original `completed_at` unchanged (idempotent).
3. IF the `treatment_step_id` does not exist, THEN THE API SHALL return HTTP 404.

---

### Requirement 8: Treatment Reminders via Push Notification

**User Story:** As a farmer, I want to set reminders for treatment steps, so that I receive a push notification when it is time to apply a treatment.

#### Acceptance Criteria

1. WHEN a `POST /api/v1/reminders` request is received with a valid `treatment_step_id` and `remind_at` timestamp, THE API SHALL create a `reminders` row and schedule a `send_reminder` Celery task to fire at `remind_at`.
2. WHEN the `send_reminder` task fires, THE Celery_Worker SHALL send an FCM push notification to the user's device containing the treatment step instruction text.
3. WHEN the reminder is sent, THE Celery_Worker SHALL update the `reminders` row `sent_at` field to the current UTC timestamp.
4. IF `remind_at` is a timestamp in the past, THEN THE API SHALL return HTTP 422 with error code `remind_at_in_past`.

---

### Requirement 9: Nearby Supplier Discovery

**User Story:** As a farmer, I want to find suppliers near me who stock the treatment I need, so that I can purchase inputs without wasting travel time.

#### Acceptance Criteria

1. WHEN a `GET /api/v1/suppliers/nearby` request is received with `latitude`, `longitude`, and `treatment_id` query parameters, THE API SHALL return suppliers that carry that treatment in stock, ordered by distance ascending.
2. THE API SHALL calculate distance using the Haversine formula and return `distance_km` on each supplier record.
3. THE API SHALL accept an optional `radius_km` query parameter (default 50, maximum 200) and exclude suppliers beyond that radius.
4. IF no suppliers are found within the requested radius, THE API SHALL return HTTP 200 with an empty list.

---

### Requirement 10: Seasonal Tips Feed

**User Story:** As a farmer, I want to browse seasonal tips relevant to my crop and region, so that I can take preventative action before disease strikes.

#### Acceptance Criteria

1. WHEN a `GET /api/v1/tips` request is received, THE API SHALL return a paginated list of tips ordered by `published_at` descending.
2. THE API SHALL support optional `crop_id`, `region`, and `language` query parameters to filter the tips feed.
3. WHEN a `language` parameter is provided, THE API SHALL return the tip `title` in the requested language if a translation exists, falling back to the default language otherwise.
4. THE API SHALL include `media_path` and `media_type` (photo or video) on each tip record.

---

### Requirement 11: Offline Sync Queue

**User Story:** As a farmer using the app in low-connectivity areas, I want my actions to be queued while offline and automatically replayed when I reconnect, so that I never lose data.

#### Acceptance Criteria

1. WHEN a `POST /api/v1/sync` request is received containing a list of `sync_queue` actions, THE API SHALL process each action in the order provided and return a result status per action.
2. WHEN a sync action corresponds to a scan upload (`action_type = create_scan`), THE API SHALL create the scan record and enqueue `run_diagnosis` as if the scan were submitted online.
3. WHEN a sync action corresponds to completing a treatment step (`action_type = complete_step`), THE API SHALL apply last-write-wins semantics using the action's `created_at` timestamp.
4. IF a sync action payload is malformed or references a non-existent entity, THEN THE API SHALL mark that action's result as `failed` with a descriptive reason and continue processing the remaining actions.
5. WHEN all actions in a `POST /api/v1/sync` request have been processed, THE API SHALL return HTTP 200 with a per-action result array.

---

### Requirement 12: Multi-Language Support

**User Story:** As a farmer who speaks a local language, I want the app content to be available in my language, so that I can understand diagnoses and treatments without needing to read English.

#### Acceptance Criteria

1. WHEN a `GET /api/v1/languages` request is received, THE API SHALL return the list of language codes supported by the `translations` table.
2. WHEN a `GET /api/v1/translations/{lang}` request is received for a supported language code, THE API SHALL return all translation strings for that language as a flat key-value JSON object.
3. WHEN a `GET /api/v1/translations/{lang}` request is received for an unsupported language code, THE API SHALL return HTTP 404 with error code `language_not_found`.
4. THE API SHALL cache translation responses in Redis with a TTL of 1 hour to reduce database load.
5. THE translations table SHALL support storing localised versions of crop names, disease names, treatment instructions, and tip titles via `entity_type` and `entity_id` columns.

---

### Requirement 13: Image Upload Validation and Storage

**User Story:** As a system operator, I want all uploaded images to be validated and stored securely, so that the ML model receives clean input and storage is not abused.

#### Acceptance Criteria

1. THE API SHALL reject image uploads where the file's MIME type does not match JPEG or PNG regardless of the file extension provided.
2. THE API SHALL generate a unique S3 key for each uploaded image using a combination of `user_id`, `scan_id`, and a UUID to prevent enumeration.
3. WHEN an image is stored in S3_Storage, THE API SHALL record the full S3 key in the `scans.image_path` column.
4. THE API SHALL not expose raw S3 bucket URLs in API responses; instead THE API SHALL return a path identifier that can be resolved server-side.

---

### Requirement 14: Background Job Reliability

**User Story:** As a system operator, I want background jobs to retry on transient failures, so that temporary network or service outages do not result in lost diagnoses or notifications.

#### Acceptance Criteria

1. THE Celery_Worker SHALL retry the `run_diagnosis` task up to 3 times with exponential backoff starting at 30 seconds on transient failures.
2. THE Celery_Worker SHALL retry the `send_reminder` task up to 3 times with exponential backoff starting at 10 seconds on FCM delivery failures.
3. WHEN a task exceeds its maximum retry count, THE Celery_Worker SHALL set the associated record status to `failed` and emit a structured error log entry containing the task name, entity id, and exception message.
4. THE Celery_Worker SHALL process `run_diagnosis` tasks on a dedicated queue named `diagnosis` and `send_reminder` tasks on a queue named `notifications` to allow independent scaling.

---

### Requirement 15: Supplier Stock Refresh

**User Story:** As a system operator, I want supplier stock information to be periodically refreshed, so that farmers are not directed to suppliers who have run out of a treatment.

#### Acceptance Criteria

1. THE Celery_Worker SHALL execute the `refresh_supplier_stock` periodic task on a configurable schedule (default: every 6 hours).
2. WHEN `refresh_supplier_stock` runs, THE Celery_Worker SHALL update the `supplier_stock.last_updated` timestamp for all processed records.
3. IF `refresh_supplier_stock` encounters an error for a specific supplier, THEN THE Celery_Worker SHALL log the error and continue processing the remaining suppliers.

---

### Requirement 16: API Documentation and Health Check

**User Story:** As a developer integrating with the backend, I want auto-generated API documentation and a health check endpoint, so that I can explore the API and verify the service is running.

#### Acceptance Criteria

1. THE API SHALL expose interactive OpenAPI documentation at `/docs` and the raw schema at `/openapi.json`.
2. WHEN a `GET /api/v1/health` request is received, THE API SHALL return HTTP 200 with a JSON body containing `status: "ok"` and the current UTC timestamp.
3. IF the database connection is unavailable when `/api/v1/health` is called, THEN THE API SHALL return HTTP 503 with `status: "degraded"` and a `detail` field describing the failure.

---

### Requirement 17: Configuration and Environment Management

**User Story:** As a developer or operator, I want all sensitive configuration loaded from environment variables, so that secrets are never committed to source control.

#### Acceptance Criteria

1. THE Application SHALL load all secrets (database credentials, JWT secret, Twilio keys, Firebase credentials, AWS keys) from environment variables via `.env` file using `python-dotenv`.
2. THE Application SHALL fail to start with a descriptive error message if any required environment variable is absent.
3. THE Application SHALL expose no secret values in API responses, logs, or error messages.

---

### Requirement 18: Database Migration Management

**User Story:** As a developer, I want schema changes managed through versioned migrations, so that database evolution is reproducible and reversible across environments.

#### Acceptance Criteria

1. THE Application SHALL manage all database schema changes through Alembic migration files stored in the `migrations/` directory.
2. WHEN the application starts, THE Application SHALL not auto-apply pending migrations; migrations SHALL be applied explicitly via the Alembic CLI.
3. THE Application SHALL seed at least 5 crops and 10 diseases with their associated treatments in an initial data migration.
