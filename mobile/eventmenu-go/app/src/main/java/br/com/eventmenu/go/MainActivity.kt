package br.com.eventmenu.go

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.ui.EventMenuGoApp
import br.com.eventmenu.go.ui.theme.EventMenuTheme
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import org.json.JSONObject

class MainActivity : FragmentActivity() {
    private var pendingTapOn: TapOnRequest? = null
    private var pendingTapOnResult: ((String?) -> Unit)? = null

    private val notificationPermissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    private val tapOnLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val request = pendingTapOn
        val callback = pendingTapOnResult
        pendingTapOn = null
        pendingTapOnResult = null
        if (request == null || callback == null) return@registerForActivityResult

        val successJson = result.data?.getStringExtra("resultTapOnSuccessJson")
        if (result.resultCode == Activity.RESULT_OK && !successJson.isNullOrBlank()) {
            val transactionCode = runCatching { JSONObject(successJson).optString("transactionCode") }.getOrNull()
            if (!transactionCode.isNullOrBlank()) {
                callback(transactionCode)
                return@registerForActivityResult
            }
        }
        callback(null)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        requestNotificationPermissionIfNeeded()
        val app = application as EventMenuGoApplication
        setContent {
            val vm: MainViewModel = viewModel(
                factory = MainViewModel.Factory(
                    app.repository,
                    app.eventRepository,
                    app.managerRepository,
                    app.notificationRepository,
                )
            )
            EventMenuTheme {
                EventMenuGoApp(
                    viewModel = vm,
                    onScan = { callback -> scanQr(callback) },
                    onBiometric = { authenticateBiometric(vm) },
                    onTapOn = ::launchTapOn,
                )
            }
        }
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT < 33) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) return
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun scanQr(onValue: (String) -> Unit) {
        val options = GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE, Barcode.FORMAT_AZTEC, Barcode.FORMAT_CODE_128)
            .enableAutoZoom()
            .build()
        GmsBarcodeScanning.getClient(this, options).startScan()
            .addOnSuccessListener { barcode -> barcode.rawValue?.let(onValue) }
    }

    private fun launchTapOn(request: TapOnRequest, onResult: (String?) -> Unit) {
        val androidId = Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID).orEmpty()
        val payload = JSONObject()
            .put("appKey", request.appKey)
            .put("appName", request.appName)
            .put("appVersion", request.appVersion)
            .put("androidId", androidId)
            .put("saleAmount", request.amountCents / 100.0)
            .put("enableTaxPassThrough", request.enableTaxPassThrough)
            .toString()

        val intent = Intent("br.com.uol.ps.tapon.OPEN_APP")
            .addCategory(Intent.CATEGORY_DEFAULT)
            .setPackage("br.com.uol.ps.tapon")
            .putExtra("TAP_ON_PAYMENT_DATA", payload)

        if (intent.resolveActivity(packageManager) == null) {
            onResult(null)
            return
        }
        pendingTapOn = request
        pendingTapOnResult = onResult
        tapOnLauncher.launch(intent)
    }

    private fun authenticateBiometric(vm: MainViewModel) {
        val allowed = BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        if (BiometricManager.from(this).canAuthenticate(allowed) != BiometricManager.BIOMETRIC_SUCCESS) return
        val prompt = BiometricPrompt(this, ContextCompat.getMainExecutor(this), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                super.onAuthenticationSucceeded(result)
                vm.restoreSession()
            }
        })
        prompt.authenticate(
            BiometricPrompt.PromptInfo.Builder()
                .setTitle("Entrar no EventMenu GO")
                .setSubtitle("Confirme sua identidade")
                .setAllowedAuthenticators(allowed)
                .build()
        )
    }
}
