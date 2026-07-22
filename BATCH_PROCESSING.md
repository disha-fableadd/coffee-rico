# Batch Processing for WhatsApp Bulk Messages

## Overview
This implementation adds batch processing capabilities to handle large volumes of WhatsApp messages efficiently, preventing timeouts and rate limiting issues.

## Features

### 1. Configurable Batch Size
- **Default**: 50 messages per batch
- **Configurable**: 1-100 messages per batch
- **Purpose**: Prevents server timeouts and API rate limiting

### 2. Delay Between Batches
- **Default**: 2 seconds between batches
- **Configurable**: 0-60 seconds
- **Purpose**: Prevents WhatsApp API rate limiting

### 3. Batch Processing Toggle
- **Default**: Enabled
- **Purpose**: Allows disabling batch processing for smaller campaigns

## Configuration

### Database Settings
The following fields are added to the `settings` table:
- `batch_size` (integer, default: 50)
- `batch_delay_seconds` (integer, default: 2)
- `enable_batch_processing` (boolean, default: true)

### API Endpoints

#### Get Batch Settings
```
GET /api/batch-settings
```

#### Update Batch Settings
```
POST /api/batch-settings
Content-Type: application/json

{
    "batch_size": 50,
    "batch_delay_seconds": 2,
    "enable_batch_processing": true
}
```

## How It Works

### 1. Campaign Processing
When a bulk message campaign is created:
1. Campaign status is set to "processing"
2. Contacts are divided into batches based on `batch_size`
3. Each batch is processed sequentially
4. Progress is updated after each batch
5. Final status is set based on results

### 2. Status Tracking
- **processing**: Campaign is being processed in batches
- **completed**: All messages sent successfully
- **completed_with_errors**: Some messages failed, some succeeded
- **failed**: All messages failed

### 3. Logging
Comprehensive logging is added for:
- Batch processing start/completion
- Individual batch progress
- Delay periods
- Success/failure counts

## Benefits

1. **Prevents Timeouts**: Large campaigns won't timeout the server
2. **Rate Limit Compliance**: Delays prevent WhatsApp API rate limiting
3. **Progress Tracking**: Real-time status updates during processing
4. **Configurable**: Adjustable batch size and delays based on needs
5. **Reliable**: Better error handling and recovery

## Usage Examples

### For 1000 contacts with default settings:
- 20 batches of 50 messages each
- 2-second delay between batches
- Total processing time: ~40 seconds + message sending time

### For 100 contacts with custom settings:
- 2 batches of 50 messages each
- 1-second delay between batches
- Total processing time: ~2 seconds + message sending time

## Migration
Run the migration to add batch processing configuration:
```bash
php artisan migrate
```

## Testing
Test with different batch sizes and delays to find optimal settings for your WhatsApp API limits and server capacity.
