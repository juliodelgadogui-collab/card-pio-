package br.com.eventmenu.go.printing

import android.content.Context

data class PrinterSettings(
    val enabled: Boolean,
    val autoPrint: Boolean,
    val deviceName: String,
    val deviceAddress: String,
    val paperWidthMm: Int,
) {
    val columns: Int get() = if (paperWidthMm >= 80) 48 else 32
}

class PrinterPreferences(context: Context) {
    private val prefs = context.getSharedPreferences("eventmenu_go_printer", Context.MODE_PRIVATE)

    fun load(): PrinterSettings = PrinterSettings(
        enabled = prefs.getBoolean("enabled", false),
        autoPrint = prefs.getBoolean("auto_print", false),
        deviceName = prefs.getString("device_name", "").orEmpty(),
        deviceAddress = prefs.getString("device_address", "").orEmpty(),
        paperWidthMm = prefs.getInt("paper_width_mm", 80).let { if (it == 58) 58 else 80 },
    )

    fun setDevice(name: String, address: String) {
        prefs.edit().putString("device_name", name).putString("device_address", address).putBoolean("enabled", address.isNotBlank()).apply()
    }

    fun setEnabled(enabled: Boolean) = prefs.edit().putBoolean("enabled", enabled).apply()
    fun setAutoPrint(enabled: Boolean) = prefs.edit().putBoolean("auto_print", enabled).apply()
    fun setPaperWidth(widthMm: Int) = prefs.edit().putInt("paper_width_mm", if (widthMm == 58) 58 else 80).apply()
    fun clearDevice() = prefs.edit().remove("device_name").remove("device_address").putBoolean("enabled", false).apply()
}

data class PrinterDevice(val name: String, val address: String)
