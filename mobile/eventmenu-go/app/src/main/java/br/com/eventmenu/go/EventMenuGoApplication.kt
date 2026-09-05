package br.com.eventmenu.go

import android.app.Application
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.EventOperationsRepository
import br.com.eventmenu.go.data.ManagerOperationsRepository
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore

class EventMenuGoApplication : Application() {
    lateinit var repository: EventMenuRepository
        private set
    lateinit var eventRepository: EventOperationsRepository
        private set
    lateinit var managerRepository: ManagerOperationsRepository
        private set

    override fun onCreate() {
        super.onCreate()
        val store = SecureSessionStore(this)
        val deviceId = DeviceIdentity.id(this)
        repository = EventMenuRepository(
            baseUrl = BuildConfig.API_BASE_URL,
            deviceId = deviceId,
            sessionStore = store,
        )
        eventRepository = EventOperationsRepository(
            baseUrl = BuildConfig.API_BASE_URL,
            deviceId = deviceId,
            sessionStore = store,
        )
        managerRepository = ManagerOperationsRepository(
            baseUrl = BuildConfig.API_BASE_URL,
            deviceId = deviceId,
            sessionStore = store,
        )
    }
}
