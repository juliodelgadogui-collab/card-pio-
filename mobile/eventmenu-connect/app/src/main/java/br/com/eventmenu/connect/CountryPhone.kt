package br.com.eventmenu.connect

import com.google.i18n.phonenumbers.PhoneNumberUtil
import java.util.Locale

data class CountryOption(
    val regionCode: String,
    val name: String,
    val callingCode: Int,
) {
    val label: String get() = "$name (+$callingCode)"
}

object CountryPhone {
    private val util: PhoneNumberUtil by lazy { PhoneNumberUtil.getInstance() }
    private val displayLocale = Locale("pt", "BR")

    val countries: List<CountryOption> by lazy {
        util.supportedRegions
            .mapNotNull { region ->
                val code = util.getCountryCodeForRegion(region)
                if (code <= 0) null
                else CountryOption(
                    regionCode = region,
                    name = Locale("", region).getDisplayCountry(displayLocale).ifBlank { region },
                    callingCode = code,
                )
            }
            .sortedWith(compareBy<CountryOption> { it.regionCode != "BR" }.thenBy { it.name })
    }

    fun defaultCountry(): CountryOption =
        countries.firstOrNull { it.regionCode == "BR" } ?: countries.first()

    fun normalize(country: CountryOption, raw: String): String {
        val trimmed = raw.trim()
        if (trimmed.isBlank()) throw IllegalArgumentException("Informe o número do WhatsApp.")
        val parsed = util.parse(trimmed, country.regionCode)
        if (!util.isValidNumberForRegion(parsed, country.regionCode) && !util.isValidNumber(parsed)) {
            throw IllegalArgumentException("Número inválido para ${country.name}.")
        }
        return util.format(parsed, PhoneNumberUtil.PhoneNumberFormat.E164).removePrefix("+")
    }

    fun preview(country: CountryOption, raw: String): String {
        if (raw.isBlank()) return "+${country.callingCode}"
        return runCatching {
            val parsed = util.parse(raw, country.regionCode)
            util.format(parsed, PhoneNumberUtil.PhoneNumberFormat.INTERNATIONAL)
        }.getOrElse {
            val digits = raw.filter(Char::isDigit)
            "+${country.callingCode}${if (digits.isBlank()) "" else " $digits"}"
        }
    }
}
