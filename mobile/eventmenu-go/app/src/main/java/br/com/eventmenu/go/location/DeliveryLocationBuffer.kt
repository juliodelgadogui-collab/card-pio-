package br.com.eventmenu.go.location

import android.content.Context
import br.com.eventmenu.go.data.DeliveryLocationSample
import org.json.JSONArray
import org.json.JSONObject

class DeliveryLocationBuffer(context: Context) {
    private val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    @Synchronized
    fun add(sample: DeliveryLocationSample) {
        val current = all().toMutableList()
        current += sample
        save(current.takeLast(MAX_POINTS))
    }

    @Synchronized
    fun all(): List<DeliveryLocationSample> {
        val raw = prefs.getString(KEY_POINTS, null) ?: return emptyList()
        return runCatching {
            val array = JSONArray(raw)
            buildList {
                for (i in 0 until array.length()) {
                    val json = array.optJSONObject(i) ?: continue
                    add(
                        DeliveryLocationSample(
                            latitude = json.getDouble("latitude"),
                            longitude = json.getDouble("longitude"),
                            accuracyM = json.optNullableDouble("accuracy_m"),
                            speedMps = json.optNullableDouble("speed_mps"),
                            bearingDeg = json.optNullableDouble("bearing_deg"),
                            capturedAtMs = json.getLong("captured_at_ms"),
                            batteryPct = if (json.has("battery_pct")) json.optInt("battery_pct") else null,
                            provider = json.optString("provider").takeIf { it.isNotBlank() },
                            isMock = json.optBoolean("is_mock", false),
                        )
                    )
                }
            }
        }.getOrDefault(emptyList())
    }

    @Synchronized
    fun clear() {
        prefs.edit().remove(KEY_POINTS).apply()
    }

    private fun save(points: List<DeliveryLocationSample>) {
        val array = JSONArray()
        points.forEach { sample ->
            val json = JSONObject()
                .put("latitude", sample.latitude)
                .put("longitude", sample.longitude)
                .put("captured_at_ms", sample.capturedAtMs)
                .put("is_mock", sample.isMock)
            sample.accuracyM?.let { json.put("accuracy_m", it) }
            sample.speedMps?.let { json.put("speed_mps", it) }
            sample.bearingDeg?.let { json.put("bearing_deg", it) }
            sample.batteryPct?.let { json.put("battery_pct", it) }
            sample.provider?.let { json.put("provider", it) }
            array.put(json)
        }
        prefs.edit().putString(KEY_POINTS, array.toString()).apply()
    }

    private fun JSONObject.optNullableDouble(key: String): Double? =
        if (has(key) && !isNull(key)) optDouble(key) else null

    companion object {
        private const val PREFS = "delivery_location_buffer"
        private const val KEY_POINTS = "points"
        private const val MAX_POINTS = 30
    }
}
