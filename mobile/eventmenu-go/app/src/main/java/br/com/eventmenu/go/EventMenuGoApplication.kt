package br.com.eventmenu.go

import android.app.Application
import br.com.eventmenu.go.data.*
import br.com.eventmenu.go.notifications.FirebasePushCoordinator
import br.com.eventmenu.go.notifications.OperationNotificationScheduler
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class EventMenuGoApplication : Application() {
    lateinit var repository:EventMenuRepository;private set
    lateinit var eventRepository:EventOperationsRepository;private set
    lateinit var eventBarRepository:EventBarRepository;private set
    lateinit var managerRepository:ManagerOperationsRepository;private set
    val managerOperationsRepository:ManagerOperationsRepository get()=managerRepository
    lateinit var notificationRepository:NotificationRepository;private set
    lateinit var receiptRepository:ReceiptRepository;private set
    lateinit var deviceStatusRepository:DeviceStatusRepository;private set
    lateinit var universalQrRepository:UniversalQrRepository;private set
    lateinit var tabSplitPaymentRepository:TabSplitPaymentRepository;private set
    lateinit var operatingUnitRepository:OperatingUnitRepository;private set
    lateinit var orderOperationsRepository:OrderOperationsRepository;private set
    lateinit var deliveryProgressRepository:DeliveryProgressRepository;private set
    lateinit var expeditionStatusRepository:ExpeditionStatusRepository;private set
    lateinit var discountRepository:DiscountRepository;private set
    lateinit var cancellationRepository:CancellationRepository;private set
    lateinit var brandRepository:TenantBrandRepository;private set
    lateinit var hubRepository:HubRepository;private set
    lateinit var pushCoordinator:FirebasePushCoordinator;private set
    lateinit var controlPlaneApi:ApiClient;private set

    private val bootstrapScope=CoroutineScope(SupervisorJob()+Dispatchers.IO)

    override fun onCreate(){
        super.onCreate()
        instance=this
        val store=SecureSessionStore(this)
        val deviceId=DeviceIdentity.id(this)
        val baseUrl=BuildConfig.API_BASE_URL
        FailoverEndpointRouter.initialize(this,baseUrl)
        ClientPolicyManager.initialize(this)
        ApiClient.configureSessionStore(store)
        ApiClient.configureOfflineCache(OfflineReadCache(this))
        controlPlaneApi=ApiClient(baseUrl,deviceId,store)

        // Consulta exclusivamente a URL principal compilada. Isso permite obter
        // antes do login a chave pública de política, a rota de contingência já
        // verificada e o manifesto Android assinado, sem confiar no nó reserva
        // para substituir a raiz de confiança.
        bootstrapScope.launch{
            runCatching{controlPlaneApi.bootstrapControlPlane()}
        }

        repository=EventMenuRepository(baseUrl,deviceId,store)
        eventRepository=EventOperationsRepository(baseUrl,deviceId,store)
        eventBarRepository=EventBarRepository(baseUrl,deviceId,store)
        managerRepository=ManagerOperationsRepository(baseUrl,deviceId,store)
        notificationRepository=NotificationRepository(baseUrl,deviceId,store)
        receiptRepository=ReceiptRepository(baseUrl,deviceId,store)
        deviceStatusRepository=DeviceStatusRepository(baseUrl,deviceId,store)
        universalQrRepository=UniversalQrRepository(baseUrl,deviceId,store)
        tabSplitPaymentRepository=TabSplitPaymentRepository(baseUrl,deviceId,store)
        operatingUnitRepository=OperatingUnitRepository(baseUrl,deviceId,store)
        orderOperationsRepository=OrderOperationsRepository(baseUrl,deviceId,store)
        deliveryProgressRepository=DeliveryProgressRepository(baseUrl,deviceId,store)
        expeditionStatusRepository=ExpeditionStatusRepository(baseUrl,deviceId,store)
        discountRepository=DiscountRepository(baseUrl,deviceId,store)
        cancellationRepository=CancellationRepository(baseUrl,deviceId,store)
        brandRepository=TenantBrandRepository(this,baseUrl,deviceId,store)
        hubRepository=HubRepository(baseUrl,deviceId,store)
        pushCoordinator=FirebasePushCoordinator(this,notificationRepository,store)
        OperationNotificationScheduler.initialize(this)
        pushCoordinator.syncCurrentToken()
    }

    companion object{lateinit var instance:EventMenuGoApplication;private set}
}
