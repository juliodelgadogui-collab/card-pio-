package br.com.eventmenu.go

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.ui.EventMenuGoApp
import br.com.eventmenu.go.ui.theme.EventMenuTheme
import com.google.android.gms.mlkit.barcode.GmsBarcodeScannerOptions
import com.google.android.gms.mlkit.barcode.GmsBarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode

class MainActivity : FragmentActivity() {
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
