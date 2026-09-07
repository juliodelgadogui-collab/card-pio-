package br.com.eventmenu.go

import android.app.Application
import br.com.eventmenu.go.data.ApiClient
import br.com.eventmenu.go.data.BrandingRepository
import br.com.eventmenu.go.data.CancellationRepository
import br.com.eventmenu.go.data.DeliveryProgressRepository
import br.com.eventmenu.go.data.DeviceStatusRepository
import br.com.eventmenu.go.data.DiscountRepository
import br.com.eventmenu.go.data.EventBarRepository
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.EventOperationsRepository
import br.com.eventmenu.go.data.FinanceRepository
import br.com.eventmenu.go.data.ManagerOperationsRepository
import br.com.eventmenu.go.data.NotificationRepository
import br.com.eventmenu.go.data.OperatingUnitRepository
import br.com.eventmenu.go.data.OrderOperationsRepository
import br.com.eventmenu.go.data.PaymentRepository
import br.com.eventmenu.go.data.ReceiptRepository
import br.com.eventmenu.go.data.TabSplitPaymentRepository
import br.com.eventmenu.go.data.UniversalQrRepository
import br.com.eventmenu.go.notifications.OperationNotificationScheduler
import br.com.eventmenu.go.payments.CardPaymentCoordinator
import br.com.eventmenu.go.payments.SumUpTapToPaySdk
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore

class EventMenuGoApplication : Application() {
    lateinit var repository: EventMenuRepository
        private set
    lateinit var eventRepository: EventOperationsRepository
        private set
    lateinit var eventBarRepository: EventBarRepository
        private set
    lateinit var managerRepository: ManagerOperationsRepository
        private set
    val managerOperationsRepository: ManagerOperationsRepository
        get() = managerRepository
    lateinit var notificationRepository: NotificationRepository
        private set
    lateinit var receiptRepository: ReceiptRepository
        private set
    lateinit var deviceStatusRepository: DeviceStatusRepository
        private set
    lateinit var universalQrRepository: UniversalQrRepository
        private set
    lateinit var tabSplitPaymentRepository: TabSplitPaymentRepository
        private set
    lateinit var operatingUnitRepository: OperatingUnitRepository
        private set
    lateinit var orderOperationsRepository: OrderOperationsRepository
        private set
    lateinit var deliveryProgressRepository: DeliveryProgressRepository
        private set
    lateinit var discountRepository: DiscountRepository
        private set
    lateinit var cancellationRepository: CancellationRepository
        private set
    lateinit var financeRepository: FinanceRepository
        private set
    lateinit var paymentRepository: PaymentRepository
        private set
    lateinit var brandingRepository: BrandingRepository
        private set
    lateinit var cardPaymentCoordinator: CardPaymentCoordinator
        private set

    private lateinit var sumUpTapToPaySdk: SumUpTapToPaySdk

    override fun onCreate() {
        super.onCreate()
        val store = SecureSessionStore(this)
        ApiClient.configureSessionStore(store)
        val deviceId = DeviceIdentity.id(this)
        val baseUrl = BuildConfig.API_BASE_URL
        repository = EventMenuRepository(baseUrl, deviceId, store)
        eventRepository = EventOperationsRepository(baseUrl, deviceId, store)
        eventBarRepository = EventBarRepository(baseUrl, deviceId, store)
        managerRepository = ManagerOperationsRepository(baseUrl, deviceId, store)
        notificationRepository = NotificationRepository(baseUrl, deviceId, store)
        receiptRepository = ReceiptRepository(baseUrl, deviceId, store)
        deviceStatusRepository = DeviceStatusRepository(baseUrl, deviceId, store)
        universalQrRepository = UniversalQrRepository(baseUrl, deviceId, store)
        tabSplitPaymentRepository = TabSplitPaymentRepository(baseUrl, deviceId, store)
        operatingUnitRepository = OperatingUnitRepository(baseUrl, deviceId, store)
        orderOperationsRepository = OrderOperationsRepository(baseUrl, deviceId, store)
        deliveryProgressRepository = DeliveryProgressRepository(baseUrl, deviceId, store)
        discountRepository = DiscountRepository(baseUrl, deviceId, store)
        cancellationRepository = CancellationRepository(baseUrl, deviceId, store)
        financeRepository = FinanceRepository(baseUrl, deviceId, store)
        brandingRepository = BrandingRepository(baseUrl, deviceId, store)

        paymentRepository = PaymentRepository(baseUrl, deviceId, store)
        sumUpTapToPaySdk = SumUpTapToPaySdk(this)
        cardPaymentCoordinator = CardPaymentCoordinator(paymentRepository) { provider ->
            when (provider) {
                "sumup" -> sumUpTapToPaySdk
                else -> error("Provedor de cartão $provider ainda não possui SDK Android habilitado.")
            }
        }

        OperationNotificationScheduler.initialize(this)
    }
}
