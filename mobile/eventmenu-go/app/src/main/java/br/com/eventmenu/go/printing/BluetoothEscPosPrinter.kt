package br.com.eventmenu.go.printing

import android.Manifest
import android.annotation.SuppressLint
import android.bluetooth.BluetoothManager
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import java.nio.charset.Charset
import java.util.UUID

class BluetoothEscPosPrinter(private val context: Context, private val preferences: PrinterPreferences) {
    private val sppUuid: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    fun hasConnectPermission(): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

    @SuppressLint("MissingPermission")
    fun bondedDevices(): List<PrinterDevice> {
        if (!hasConnectPermission()) return emptyList()
        val adapter = (context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager).adapter ?: return emptyList()
        return adapter.bondedDevices.orEmpty()
            .map { PrinterDevice(it.name.orEmpty().ifBlank { "Bluetooth ${it.address.takeLast(5)}" }, it.address) }
            .sortedBy { it.name.lowercase() }
    }

    @SuppressLint("MissingPermission")
    fun print(text: String) {
        if (!hasConnectPermission()) throw IllegalStateException("Permita acesso aos dispositivos Bluetooth para imprimir.")
        val settings = preferences.load()
        if (!settings.enabled || settings.deviceAddress.isBlank()) throw IllegalStateException("Configure uma impressora Bluetooth no Perfil.")
        val adapter = (context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager).adapter ?: throw IllegalStateException("Bluetooth não disponível neste aparelho.")
        if (!adapter.isEnabled) throw IllegalStateException("Ative o Bluetooth antes de imprimir.")
        val device = adapter.getRemoteDevice(settings.deviceAddress)
        val socket = device.createRfcommSocketToServiceRecord(sppUuid)
        try {
            socket.connect()
            val out = socket.outputStream
            out.write(byteArrayOf(0x1B, 0x40))
            out.write(byteArrayOf(0x1B, 0x61, 0x00))
            val formatted = wrapReceipt(text, settings.columns)
            out.write(formatted.toByteArray(Charset.forName("CP860")))
            out.write("\n\n\n".toByteArray(Charsets.US_ASCII))
            out.write(byteArrayOf(0x1D, 0x56, 0x01))
            out.flush()
        } finally {
            runCatching { socket.close() }
        }
    }

    private fun wrapReceipt(text: String, columns: Int): String = buildString {
        text.lines().forEach { raw ->
            val line = raw.trimEnd()
            if (line.length <= columns) {
                appendLine(line)
                return@forEach
            }
            var remaining = line
            while (remaining.length > columns) {
                var cut = remaining.lastIndexOf(' ', columns)
                if (cut < columns / 2) cut = columns
                appendLine(remaining.substring(0, cut).trimEnd())
                remaining = remaining.substring(cut).trimStart()
            }
            appendLine(remaining)
        }
    }
}
