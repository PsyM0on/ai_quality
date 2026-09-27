<?php
/**
 * ECO QUALITY - CENTRALIZED ENVIRONMENTAL METRICS & ATMOSPHERIC CALCULATIONS
 * 
 * Standards & Compliance References:
 * 1. Philippine Clean Air Act of 1999 (Republic Act No. 8749)
 * 2. DENR EMB Department Administrative Order No. 2000-81 (DAO 2000-81)
 *    Air Quality Indices and Breakpoints for Particulate Matter 10 (PM10)
 * 3. PAGASA (Philippine Atmospheric, Geophysical and Astronomical Services Administration)
 *    Heat Index Classification Scheme and Rothfusz Regression Model
 * 4. NFPA 72 / UL 521 Fixed-Temperature Heat Detection Standards
 * 5. UESI (Urban Environmental Stress Index) Multi-Hazard Exposure Framework
 * 
 * Single source of truth across dashboard.php, insert.php, export.php, and backfills.
 */

// ── PHILIPPINE DENR EMB PM10 BREAKPOINTS (DAO 2000-81) ───────────────────────
const DENR_PM10_BREAKPOINTS = [
    // [C_low, C_high, I_low, I_high]
    [0.0,   54.0,   0,   50],    // Good
    [55.0,  154.0,  51,  100],   // Fair
    [155.0, 254.0,  101, 150],   // Unhealthy for Sensitive Groups
    [255.0, 354.0,  151, 200],   // Very Unhealthy
    [355.0, 424.0,  201, 300],   // Acutely Unhealthy
    [425.0, 504.0,  301, 500]    // Emergency (DAO 2000-81 Annex A)
];

/**
 * Calculate AQI from PM10 concentration using official DENR DAO 2000-81 linear interpolation.
 * 
 * @param float $pm10 PM10 concentration in ug/m3
 * @return int AQI value (0 - 500)
 */
function calc_pm10_aqi($pm10) {
    $pm = max(0.0, floatval($pm10));
    if ($pm > 504.0) {
        return 500;
    }
    foreach (DENR_PM10_BREAKPOINTS as $bp) {
        list($cl, $ch, $il, $ih) = $bp;
        if ($pm >= $cl && $pm <= $ch) {
            return (int)round((($ih - $il) / ($ch - $cl)) * ($pm - $cl) + $il);
        }
    }
    return 500;
}

/**
 * Return Philippine DENR EMB AQI category label.
 * 
 * @param int|float $aqi
 * @return string
 */
function get_aqi_category($aqi) {
    $v = floatval($aqi);
    if ($v <= 50)  return "Good";
    if ($v <= 100) return "Fair";
    if ($v <= 150) return "Unhealthy for Sensitive Groups";
    if ($v <= 200) return "Very Unhealthy";
    if ($v <= 300) return "Acutely Unhealthy";
    return "Emergency";
}

/**
 * Return official project color hex corresponding to AQI severity tier.
 * 
 * @param int|float $aqi
 * @return string
 */
function get_aqi_color($aqi) {
    $v = floatval($aqi);
    if ($v <= 50)  return "#00CFA8"; // Good (Cyan-Green)
    if ($v <= 100) return "#F5A623"; // Fair (Amber)
    if ($v <= 150) return "#FF8C00"; // Unhealthy for Sensitive Groups (Orange)
    if ($v <= 200) return "#F05252"; // Very Unhealthy (Red)
    if ($v <= 300) return "#9B59B6"; // Acutely Unhealthy (Purple)
    return "#7B241C";               // Emergency (Maroon)
}

/**
 * Return official Philippine DENR EMB cautionary health advisory text (DAO 2000-81 Annex A).
 * 
 * @param int|float $aqi
 * @return string
 */
function get_aqi_advice($aqi) {
    $v = floatval($aqi);
    if ($v <= 50) {
        return "Air quality is satisfactory. No air pollution health risks (DENR Good).";
    } elseif ($v <= 100) {
        return "Air quality is acceptable (Fair). Unusually sensitive individuals should consider limiting prolonged outdoor exertion.";
    } elseif ($v <= 150) {
        return "People with respiratory disease, such as asthma, should limit outdoor exertion (DAO 2000-81).";
    } elseif ($v <= 200) {
        return "Pedestrians should avoid heavy traffic areas. People with heart or respiratory disease, such as asthma, should stay indoors and rest as much as possible. Unnecessary trips should be postponed. People should voluntarily restrict vehicle use (DAO 2000-81).";
    } elseif ($v <= 300) {
        return "People should limit outdoor exertion. People with heart or respiratory disease, such as asthma, should stay indoors and rest as much as possible. Unnecessary trips should be postponed. Motor vehicle use may be restricted (DAO 2000-81).";
    } else {
        return "EMERGENCY. Everyone should remain indoors, (keeping windows and doors closed unless heat stress is possible). Motor vehicle use prohibited except emergencies; industrial activities curtailed (DAO 2000-81).";
    }
}

/**
 * Calculate multi-pollutant environmental health risk score (0 - 100 scale).
 * Weighted formula: 50% PM10, 30% VOCs/Gas (MQ-135), 10% Humidity Deviation, 10% Temperature Thermal Stress.
 * 
 * @param float $pm10  PM10 in ug/m3
 * @param int   $mq135 Raw analog/calibrated MQ-135 reading
 * @param float $temp  Ambient temperature in Celsius
 * @param float $hum   Relative humidity in %
 * @return float Health risk score (0.0 - 100.0)
 */
function calc_health_risk_score($pm10, $mq135, $temp, $hum) {
    $pm_score  = min(floatval($pm10) / 325.4, 1.0) * 100.0;
    $voc_score = min(max(floatval($mq135) - 300.0, 0.0) / 400.0, 1.0) * 100.0;
    $hum_score = min(max(abs(floatval($hum) - 50.0) - 10.0, 0.0) / 40.0, 1.0) * 100.0;
    $tmp_score = min(max(floatval($temp) - 35.0, 0.0) / 15.0, 1.0) * 100.0;

    $score = ($pm_score * 0.50) + ($voc_score * 0.30) + ($hum_score * 0.10) + ($tmp_score * 0.10);
    return round(min($score, 100.0), 1);
}

/**
 * Return health risk tier label based on health risk score and thermal safety thresholds (NFPA 72).
 * 
 * @param float $health_score (0 - 100)
 * @param float $temp Ambient temperature in Celsius
 * @return string
 */
function get_health_risk_level($health_score, $temp) {
    $t = floatval($temp);
    $hs = floatval($health_score);

    if ($t > 74.0)       return "Critical Risk"; // NFPA 72 Critical Fire
    if ($t >= 57.0)      return "High Risk";     // NFPA 72 Early Warning
    if ($hs <= 20.0)     return "Low Risk";
    if ($hs <= 40.0)     return "Mild Risk";
    if ($hs <= 60.0)     return "Moderate Risk";
    if ($hs <= 80.0)     return "High Risk";
    return "Critical Risk";
}

/**
 * Return K-Means unsupervised cluster pattern categorization.
 * 
 * @param int|float $aqi
 * @param float $pm10
 * @return string ("Clean", "Moderate", "Polluted")
 */
function get_cluster_label($aqi, $pm10) {
    $a = floatval($aqi);
    $p = floatval($pm10);
    if ($a <= 50.0 && $p <= 54.0) return "Clean";
    if ($a <= 100.0 && $p <= 154.0) return "Moderate";
    return "Polluted";
}

/**
 * Calculate PAGASA / Rothfusz Heat Index from Ambient Temperature and Relative Humidity.
 * 
 * @param float $temp_c Temperature in Celsius
 * @param float $hum    Relative Humidity in %
 * @return array ['temp_c', 'temp_f', 'heat_index_c', 'heat_index_f', 'category', 'color', 'description']
 */
function calc_pagasa_heat_index($temp_c, $hum) {
    $T_c = floatval($temp_c);
    $RH  = floatval($hum);
    $T_f = ($T_c * 9.0 / 5.0) + 32.0;

    if ($T_f < 80.0) {
        // Simplified Steadman formula for mild temperatures
        $HI_f = 0.5 * ($T_f + 61.0 + (($T_f - 68.0) * 1.2) + ($RH * 0.094));
    } else {
        // Full Rothfusz polynomial regression
        $HI_f = -42.379 + (2.04901523 * $T_f) + (10.14333127 * $RH)
                - (0.22475541 * $T_f * $RH) - (0.00683783 * $T_f * $T_f)
                - (0.05481717 * $RH * $RH) + (0.00122874 * $T_f * $T_f * $RH)
                + (0.00085282 * $T_f * $RH * $RH) - (0.00000199 * $T_f * $T_f * $RH * $RH);

        // Adjustments for extreme low/high humidity ranges
        if ($RH < 13.0 && $T_f >= 80.0 && $T_f <= 112.0) {
            $adj = ((13.0 - $RH) / 4.0) * sqrt(max(0.0, (17.0 - abs($T_f - 95.0)) / 17.0));
            $HI_f -= $adj;
        } elseif ($RH > 85.0 && $T_f >= 80.0 && $T_f <= 87.0) {
            $adj = (($RH - 85.0) / 10.0) * ((87.0 - $T_f) / 5.0);
            $HI_f += $adj;
        }
    }

    $HI_c = round(($HI_f - 32.0) * 5.0 / 9.0, 1);

    // Official PAGASA 5-Tier Thermal Hazard Scale
    if ($HI_c < 27.0) {
        $cat   = "Normal";
        $color = "#00CFA8";
        $desc  = "Minimal thermal stress.";
    } elseif ($HI_c <= 32.0) {
        $cat   = "Caution";
        $color = "#4C9EEB";
        $desc  = "Fatigue possible with prolonged exposure.";
    } elseif ($HI_c <= 41.0) {
        $cat   = "Extreme Caution";
        $color = "#F5A623";
        $desc  = "Heat cramps and heat exhaustion possible.";
    } elseif ($HI_c <= 51.0) {
        $cat   = "Danger";
        $color = "#F05252";
        $desc  = "Heat exhaustion likely; heat stroke probable.";
    } else {
        $cat   = "Extreme Danger";
        $color = "#C084FC";
        $desc  = "Heat stroke imminent with continued exposure.";
    }

    return [
        'temp_c'        => $T_c,
        'temp_f'        => round($T_f, 1),
        'heat_index_c'  => $HI_c,
        'heat_index_f'  => round($HI_f, 1),
        'category'      => $cat,
        'color'         => $color,
        'description'   => $desc
    ];
}

/**
 * Calculate Urban Environmental Stress Index (UESI) multi-hazard advisory.
 * Evaluates coupled air pollution (24h sustained AQI) and atmospheric thermal stress.
 * 
 * @param int|float $aqi_24h Sustained 24h average AQI
 * @param float     $hi_c    PAGASA heat index in Celsius
 * @return array ['level', 'color', 'advice']
 */
function calc_uesi($aqi_24h, $hi_c) {
    $aqi = floatval($aqi_24h);
    $hi  = floatval($hi_c);

    if ($aqi > 150.0 || $hi >= 42.0) {
        return [
            'level'  => "High Environmental Stress",
            'color'  => "#F05252",
            'advice' => "Dual hazard: High pollution and severe thermal heat. Vulnerable individuals avoid outdoor exertion."
        ];
    } elseif ($aqi > 100.0 || $hi >= 33.0) {
        return [
            'level'  => "Moderate Environmental Stress",
            'color'  => "#F5A623",
            'advice' => "Elevated environmental factor detected. Stay hydrated and limit prolonged roadside exertion."
        ];
    } else {
        return [
            'level'  => "Optimal Urban Conditions",
            'color'  => "#00CFA8",
            'advice' => "Normal atmospheric dispersion and comfortable thermal conditions."
        ];
    }
}

/**
 * Rapid rule-based anomaly detection for ingestion telemetry.
 * 
 * @param float $pm10
 * @param float $temp
 * @param int   $mq135
 * @param float $slope
 * @return array ['is_anomaly' => int, 'severity' => string]
 */
function calc_rule_anomaly($pm10, $temp, $mq135, $slope = 0.0) {
    $p = floatval($pm10);
    $t = floatval($temp);
    $m = intval($mq135);
    $s = abs(floatval($slope));

    if ($p >= 255.0 || $t >= 57.0 || $m >= 700 || $s >= 30.0) {
        return ['is_anomaly' => 1, 'severity' => 'critical'];
    } elseif ($p >= 155.0 || $m >= 500 || $s >= 15.0) {
        return ['is_anomaly' => 1, 'severity' => 'warning'];
    }
    return ['is_anomaly' => 0, 'severity' => 'normal'];
}

