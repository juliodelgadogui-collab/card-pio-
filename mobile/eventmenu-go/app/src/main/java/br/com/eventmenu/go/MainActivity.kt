package br.com.eventmenu.go

import android.app.Activity
import android.content.Intent
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
import com.google.android.gms.mlkit.barcode.GmsBarcodeScannerOptions
import com.google.android.gms.mlkit.barcode.GmsBarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import org.json.JSONObject

class MainActivity : FragmentActivity() {
    private var pendingTapOn: TapOnRequest? = null
    private var pendingTapOnVm: MainViewModel? = null

    private val tapOnLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val request = pendingTapOn
        val vm = pendingTapOnVm
        pendingTapOn = null
        pendingTapOnVm = null
        if (request == null || vm == null) return@registerForActivityResult

        val successJson = result.data?.getStringExtra("resultTapOnSuccessJson")
        if (result.resultCode == Activity.RESULT_OK && !successJson.isNullOrBlank()) {
            val transactionCode = runCatching { JSONObject(successJson).optString("transactionCode") }.getOrNull()
            if (!transactionCode.isNullOrBlank()) {
                vm.verifyTapOn(request, transactionCode)
                return@registerForActivityResult
            }
        }
        vm.tapOnCancelled()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val repository = (application as EventMenuGoApplication).repository
        setContent {
            val vm: MainViewModel = viewModel(factory = MainViewModel.Factory(repository))
            EventMenuTheme {
                EventMenuGoApp(
                    viewModel = vm,
                    onScan = { scanQr(vm) },
                    onBiometric = { authenticateBiometric(vm) },
                    onTapOn = { request -> launchTapOn(request, vm) },
                )
            }
        }
    }

    private fun scanQr(vm: MainViewModel) {
        val options = GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE, Barcode.FORMAT_AZTEC, Barcode.FORMAT_CODE_128)
            .enableAutoZoom()
            .build()
        GmsBarcodeScanning.getClient(this, options).startScan()
            .addOnSuccessListener { barcode -> barcode.rawValue?.let(vm::resolveQr) }
    }

    private fun launchTapOn(request: TapOnRequest, vm: MainViewModel) {
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
            vm.tapOnCancelled()
            return
        }
        pendingTapOn = request
        pendingTapOnVm = vm
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
