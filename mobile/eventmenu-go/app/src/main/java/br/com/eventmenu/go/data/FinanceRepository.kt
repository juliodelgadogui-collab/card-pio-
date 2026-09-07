package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class FinanceSummary(
    val unitName: String,
    val from: String,
    val to: String,
    val incomeCents: Int,
    val expenseCents: Int,
    val resultCents: Int,
    val cashInCents: Int,
    val cashOutCents: Int,
    val cashChangeCents: Int,
    val overdueReceivableCents: Int,
    val overduePayableCents: Int,
    val forecastIn30dCents: Int,
    val forecastOut30dCents: Int,
    val forecastChange30dCents: Int,
    val operatingResultCents: Int,
    val openEntries: List<FinanceOpenEntry>,
    val accounts: List<FinanceAccountBalance>,
)

data class FinanceOpenEntry(
    val id: Int,
    val direction: String,
    val description: String,
    val amountCents: Int,
    val dueDate: String,
    val category: String,
)

data class FinanceAccountBalance(
    val id: Int,
    val name: String,
    val type: String,
    val balanceCents: Int,
)

class FinanceRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun summary(from: String = "", to: String = ""): FinanceSummary {
        val query = buildMap {
            if (from.isNotBlank()) put("from", from)
            if (to.isNotBlank()) put("to", to)
        }
        val root = api.getFinance(token(), query)
        val scope = root.optJSONObject("scope") ?: JSONObject()
        val s = root.optJSONObject("summary") ?: JSONObject()
        val dre = root.optJSONObject("dre") ?: JSONObject()
        return FinanceSummary(
            unitName = scope.optString("unit_name", "Empresa"),
            from = root.optString("from"),
            to = root.optString("to"),
            incomeCents = s.optInt("income_cents"),
            expenseCents = s.optInt("expense_cents"),
            resultCents = s.optInt("result_cents"),
            cashInCents = s.optInt("cash_in_cents"),
            cashOutCents = s.optInt("cash_out_cents"),
            cashChangeCents = s.optInt("cash_change_cents"),
            overdueReceivableCents = s.optInt("overdue_receivable_cents"),
            overduePayableCents = s.optInt("overdue_payable_cents"),
            forecastIn30dCents = s.optInt("forecast_in_30d_cents"),
            forecastOut30dCents = s.optInt("forecast_out_30d_cents"),
            forecastChange30dCents = s.optInt("forecast_change_30d_cents"),
            operatingResultCents = dre.optInt("operating_result_cents"),
            openEntries = parseOpen(root.optJSONArray("open") ?: JSONArray()),
            accounts = parseAccounts(root.optJSONArray("accounts") ?: JSONArray()),
        )
    }

    private fun parseOpen(array: JSONArray) = buildList {
        for (i in 0 until array.length()) {
            val j = array.getJSONObject(i)
            add(FinanceOpenEntry(j.optInt("id"), j.optString("direction"), j.optString("description"), j.optInt("net_cents"), j.optString("due_date"), j.optString("category_name")))
        }
    }

    private fun parseAccounts(array: JSONArray) = buildList {
        for (i in 0 until array.length()) {
            val j = array.getJSONObject(i)
            add(FinanceAccountBalance(j.optInt("id"), j.optString("name"), j.optString("account_type"), j.optInt("balance_cents")))
        }
    }

    private fun token() = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
