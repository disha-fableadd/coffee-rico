# Scheduled Messages Cron Setup

This document explains how to set up the cron job for processing scheduled bulk messages.

## Overview

The system includes a Laravel command `bulk:process-scheduled-messages` that checks for scheduled bulk messages every minute and sends them when their scheduled time arrives.

## Command Details

- **Command**: `bulk:process-scheduled-messages`
- **Frequency**: Every minute
- **Purpose**: Process scheduled bulk messages and send WhatsApp messages

## Setup Instructions

### 1. Laravel Scheduler (Recommended)

The command is already registered in `app/Console/Kernel.php` to run every minute. To activate the Laravel scheduler, add this cron entry:

```bash
* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Direct Command Execution

Alternatively, you can run the command directly every minute:

```bash
* * * * * cd /path/to/your/project && php artisan bulk:process-scheduled-messages >> /dev/null 2>&1
```

### 3. Windows Task Scheduler (For Windows Servers)

1. Open Task Scheduler
2. Create a new task
3. Set trigger to "Daily" and repeat every 1 minute
4. Set action to start a program:
   - Program: `php`
   - Arguments: `artisan bulk:process-scheduled-messages`
   - Start in: `E:\xampp_8_2\htdocs\whatsapp-bulk-backend\whatsapp-bulk-laravel`

## How It Works

1. The command runs every minute
2. It finds all bulk messages with status 'scheduled' where `scheduleAt <= now()`
3. For each scheduled message:
   - Updates status to 'sending'
   - Validates template and user permissions
   - Checks active package and message quota
   - Sends WhatsApp messages to all contacts
   - Updates campaign status to 'completed' or 'failed'
   - Updates used message count in the active package

## Testing

You can test the command manually:

```bash
php artisan bulk:process-scheduled-messages
```

## Logs

The command provides detailed logging output showing:
- Number of scheduled messages found
- Processing status for each campaign
- Success/failure counts for each campaign
- Any errors encountered

## Troubleshooting

1. **Command not found**: Make sure you're in the Laravel project directory
2. **Permission errors**: Ensure the web server has write permissions to the database
3. **WhatsApp API errors**: Check your WhatsApp API configuration and credentials
4. **Database connection**: Verify your database connection settings

## Security Notes

- The command runs with the same permissions as the web server
- Ensure your server is secure and properly configured
- Monitor logs for any suspicious activity
- Consider setting up log rotation for command output
