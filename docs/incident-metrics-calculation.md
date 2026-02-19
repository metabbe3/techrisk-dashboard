# Incident Metrics Calculation (MTTR & MTBF)

## Overview

This document explains how MTTR (Mean Time To Resolve) and MTBF (Mean Time Between Failures) are calculated for incidents.

## MTTR Calculation Rules

| Fund Status | Calculation Method | Storage Format | Example |
|-------------|-------------------|----------------|---------|
| **Non fundLoss** | Minutes (includes time) | Positive value | Jan 9, 10:00 → Jan 9, 12:00 = **120 mins** |
| **Confirmed loss** | Days (date-only) | Negative value | Jan 9 → Jan 11 = **-2** (2 days) |
| **Potential recovery** | Days (date-only) | Negative value | Jan 9 → Jan 11 = **-2** (2 days) |

**Note:** Day-based MTTR is stored as negative to distinguish from minute-based values.

## MTBF Calculation Rules

- **Date-only calculation** (ignores time)
- **First incident of year:** Days from Jan 1st to incident date
- **Subsequent incidents:** Days from previous incident date to current incident date

**Examples:**
- Jan 9 → Jan 11 = **2 days** (date-only, time ignored)
- Jan 1 → Jan 9 = **8 days** (first incident of year)

## How to Use the Recalculation Command

### Run in Docker Container

```bash
# Recalculate all incidents
docker exec techrisk-app php artisan incidents:recalculate-metrics

# Recalculate for specific year
docker exec techrisk-app php artisan incidents:recalculate-metrics --year=2025

# Dry run (preview changes without saving)
docker exec techrisk-app php artisan incidents:recalculate-metrics --dry-run
```

### Run Directly (in production server)

```bash
# Recalculate all incidents
php artisan incidents:recalculate-metrics

# Recalculate for specific year
php artisan incidents:recalculate-metrics --year=2025

# Dry run (preview changes without saving)
php artisan incidents:recalculate-metrics --dry-run
```

## Command Options

| Option | Description |
|--------|-------------|
| `--year=YYYY` | Only recalculate for specific year |
| `--force` | Force recalculation even if values exist |
| `--dry-run` | Show what would be changed without making changes |

## Files Modified

1. **`app/Models/Incident.php`**
   - Added `shouldCalculateMttrByDays()` method to check fund status

2. **`app/Observers/IncidentObserver.php`**
   - Updated MTTR calculation to use fund status instead of fund loss amount
   - Fixed MTTR day calculation to use date-only (`startOfDay()`)
   - Fixed MTBF calculation to use date-only (`startOfDay()`)

3. **`app/Console/Commands/RecalculateIncidentMetricsCommand.php`**
   - New Artisan command for recalculating MTTR and MTBF
   - Supports filtering by year and dry-run mode

## Automated Updates

The metrics are automatically recalculated when:
- An incident is **created**
- An incident is **updated** (if `incident_date` or `stop_bleeding_at` changes)
- An adjacent incident's date changes (updates MTBF for neighboring incidents)

## Common Scenarios

### Scenario 1: Non Fund Loss Incident
- **Incident Date:** Jan 9, 2025, 10:00
- **Stop Bleeding:** Jan 9, 2025, 12:00
- **Fund Status:** Non fundLoss
- **MTTR:** 120 minutes
- **MTBF:** 8 days (from Jan 1)

### Scenario 2: Confirmed Loss Incident
- **Incident Date:** Jan 9, 2025, 23:59
- **Stop Bleeding:** Jan 11, 2025, 00:01
- **Fund Status:** Confirmed loss
- **MTTR:** 2 days (date-only, time ignored)
- **MTBF:** 8 days (from Jan 1)

### Scenario 3: Potential Recovery Incident
- **Incident Date:** Jan 9, 2025, 10:00
- **Stop Bleeding:** Jan 15, 2025, 18:00
- **Fund Status:** Potential recovery
- **MTTR:** 6 days (date-only, time ignored)
- **MTBF:** 8 days (from Jan 1)

## Troubleshooting

### MTTR shows incorrect value
1. Check `fund_status` column (should be "Non fundLoss", "Confirmed loss", or "Potential recovery")
2. Check `stop_bleeding_at` is set
3. Run recalculation command: `php artisan incidents:recalculate-metrics`

### MTBF shows incorrect value
1. Verify date calculation is date-only (time ignored)
2. Check if there's a previous incident in the same year
3. Run recalculation command: `php artisan incidents:recalculate-metrics`

### Command not found
1. Clear Laravel cache: `php artisan config:clear`
2. Clear route cache: `php artisan route:clear`
3. Try again
