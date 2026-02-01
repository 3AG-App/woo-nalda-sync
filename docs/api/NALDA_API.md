# 3AG Nalda API Documentation

**Base URL:** `https://3ag.app/api/v3`

This API allows the Nalda plugin to upload CSV files to the Nalda SFTP server and manage upload history.

---

## Authentication

All Nalda API endpoints require:
- A valid `license_key`
- A `domain` that is currently activated for the license

The API validates that the license is active and the domain has a valid activation before processing requests.

---

## Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `/nalda/csv-upload` | 10 requests/minute |
| `/nalda/csv-upload/list` | 60 requests/minute |
| `/nalda/sftp-validate` | 30 requests/minute |

---

## Endpoints

### 1. Upload CSV File

Uploads a CSV file to the Nalda SFTP server. The file is also backed up to cloud storage for record-keeping.

**Endpoint:** `POST /nalda/csv-upload`

**Content-Type:** `multipart/form-data`

**Request Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `license_key` | string | Yes | The license key |
| `domain` | string | Yes | The activated domain making the request |
| `csv_type` | string | Yes | Type of CSV: `orders` or `products` |
| `sftp_host` | string | Yes | SFTP hostname (must be `*.nalda.com`) |
| `sftp_port` | integer | No | SFTP port (default: `2022`) |
| `sftp_username` | string | Yes | SFTP username |
| `sftp_password` | string | Yes | SFTP password |
| `csv_file` | file | Yes | The CSV file to upload (max 10MB) |

**SFTP Upload Paths:**

| CSV Type | Remote Path |
|----------|-------------|
| `orders` | `/order-status/{filename}` |
| `products` | `/{filename}` |

**Example Request (cURL):**

```bash
curl -X POST "https://3ag.app/api/v3/nalda/csv-upload" \
  -H "Accept: application/json" \
  -F "license_key=XXXX-XXXX-XXXX-XXXX" \
  -F "domain=mystore.com" \
  -F "csv_type=orders" \
  -F "sftp_host=ftp.nalda.com" \
  -F "sftp_port=2022" \
  -F "sftp_username=myuser" \
  -F "sftp_password=mypassword" \
  -F "csv_file=@/path/to/orders.csv"
```

**Success Response (201):**

```json
{
  "data": {
    "id": 123,
    "type": "orders",
    "file_name": "orders_20250124_143052_123.csv",
    "status": "uploaded",
    "created_at": "2025-01-24T14:30:52+00:00"
  }
}
```

**Error Responses:**

| Status | Message | Description |
|--------|---------|-------------|
| 400 | `License key and domain are required.` | Missing required auth fields |
| 401 | `Invalid license key.` | License key not found |
| 403 | `License is not active.` | License is suspended or cancelled |
| 403 | `License has expired.` | License expiration date has passed |
| 403 | `License is not activated on this domain.` | Domain not activated |
| 422 | Validation errors | Invalid request parameters |
| 500 | `Failed to upload to SFTP server. Please check your credentials and try again.` | SFTP upload failed |

---

### 2. List CSV Uploads

Retrieves a paginated list of previous CSV uploads for the current license and domain.

**Endpoint:** `GET /nalda/csv-upload/list`

**Query Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `license_key` | string | Yes | The license key |
| `domain` | string | Yes | The activated domain |
| `type` | string | No | Filter by CSV type: `orders` or `products` |
| `per_page` | integer | No | Results per page (1-100, default: 15) |
| `page` | integer | No | Page number (default: 1) |

**Example Request:**

```
GET /api/v3/nalda/csv-upload/list?license_key=XXXX-XXXX-XXXX-XXXX&domain=mystore.com&type=products&per_page=10&page=1
```

**Success Response (200):**

```json
{
  "data": [
    {
      "id": 125,
      "type": "products",
      "file_name": "products_20250124_160000_125.csv",
      "status": "uploaded",
      "created_at": "2025-01-24T16:00:00+00:00"
    },
    {
      "id": 124,
      "type": "orders",
      "file_name": "orders_20250124_150000_124.csv",
      "status": "uploaded",
      "created_at": "2025-01-24T15:00:00+00:00"
    },
    {
      "id": 123,
      "type": "orders",
      "file_name": "orders_20250124_143052_123.csv",
      "status": "failed",
      "created_at": "2025-01-24T14:30:52+00:00"
    }
  ],
  "links": {
    "first": "https://3ag.app/api/v3/nalda/csv-upload/list?page=1",
    "last": "https://3ag.app/api/v3/nalda/csv-upload/list?page=3",
    "prev": null,
    "next": "https://3ag.app/api/v3/nalda/csv-upload/list?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 10,
    "to": 10,
    "total": 25
  }
}
```

**Error Responses:**

| Status | Message | Description |
|--------|---------|-------------|
| 400 | `License key and domain are required.` | Missing required auth fields |
| 401 | `Invalid license key.` | License key not found |
| 403 | `License is not active.` | License is suspended or cancelled |
| 403 | `License has expired.` | License expiration date has passed |
| 403 | `License is not activated on this domain.` | Domain not activated |
| 422 | Validation errors | Invalid query parameters |

---

### 3. Validate SFTP Credentials

Tests SFTP connection without uploading a file. Use this to verify credentials before attempting an upload.

**Endpoint:** `POST /nalda/sftp-validate`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `license_key` | string | Yes | The license key |
| `domain` | string | Yes | The activated domain |
| `sftp_host` | string | Yes | SFTP hostname (must be `*.nalda.com`) |
| `sftp_port` | integer | No | SFTP port (default: `2022`) |
| `sftp_username` | string | Yes | SFTP username |
| `sftp_password` | string | Yes | SFTP password |

**Example Request:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "domain": "mystore.com",
  "sftp_host": "ftp.nalda.com",
  "sftp_port": 2022,
  "sftp_username": "myuser",
  "sftp_password": "mypassword"
}
```

**Success Response (200):**

```json
{
  "data": []
}
```

**Error Responses:**

| Status | Message | Description |
|--------|---------|-------------|
| 400 | `License key and domain are required.` | Missing required auth fields |
| 401 | `Invalid license key.` | License key not found |
| 403 | `License is not active.` | License is suspended or cancelled |
| 403 | `License has expired.` | License expiration date has passed |
| 403 | `License is not activated on this domain.` | Domain not activated |
| 422 | `Authentication failed.` | Invalid SFTP username or password |
| 422 | `Connection failed. Please check the hostname and port.` | Cannot connect to SFTP server |
| 422 | `SFTP hostname must be a *.nalda.com domain.` | Invalid hostname |

---

## Implementation Guide

### Recommended Plugin Flow

1. **Settings Page - SFTP Configuration:**
   - Allow user to enter SFTP credentials
   - Call `/nalda/sftp-validate` to test credentials
   - Store credentials securely if validation succeeds

2. **CSV Export & Upload:**
   - Generate CSV file (orders or products)
   - Call `/nalda/csv-upload` to upload the file
   - Display success/failure message to user
   - Optionally store upload ID for reference

3. **Upload History Page:**
   - Call `/nalda/csv-upload/list` to display past uploads
   - Show upload status (uploaded/failed)
   - Implement pagination for large histories

### CSV Type Reference

| Type | Value | Description | SFTP Path |
|------|-------|-------------|-----------|
| Orders | `orders` | Order status exports | `/order-status/` |
| Products | `products` | Product catalog exports | `/` (root) |

### File Requirements

- **Format:** CSV or TXT file
- **Max Size:** 10 MB
- **Encoding:** UTF-8 recommended

### SFTP Host Restriction

For security, the API only allows uploads to `*.nalda.com` domains. Any other SFTP hostname will be rejected with a 422 error.

---

## Response Field Reference

### Upload Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique upload identifier |
| `type` | string | CSV type: `orders` or `products` |
| `file_name` | string | Generated filename on storage |
| `status` | string | Upload status: `processing`, `uploaded`, or `failed` |
| `created_at` | string | ISO 8601 timestamp |

### Upload Status Values

| Status | Description |
|--------|-------------|
| `processing` | Upload in progress |
| `uploaded` | Successfully uploaded to SFTP |
| `failed` | Upload failed (check error message) |

---

## Error Handling

All error responses follow this format:

```json
{
  "message": "Error description here."
}
```

For validation errors (422):

```json
{
  "message": "The csv type field is required.",
  "errors": {
    "csv_type": ["The csv type field is required."],
    "sftp_host": ["SFTP hostname must be a *.nalda.com domain."]
  }
}
```

---

## Security Notes

1. **License Validation:** Every request validates the license is active and the domain is properly activated
2. **SFTP Host Whitelist:** Only `*.nalda.com` hosts are allowed for security
3. **Credentials:** SFTP passwords are never stored; they must be provided with each upload request
4. **Rate Limiting:** Strict rate limits prevent abuse
5. **File Backup:** All uploaded files are backed up to secure cloud storage for audit purposes
