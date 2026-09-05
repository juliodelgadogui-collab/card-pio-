package br.com.eventmenu.go

import android.app.Application
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore

class EventMenuGoApplication : Application() {
    lateinit var repository: EventMenuRepository
        private set

    override fun onCreate() {
        super.onCreate()
        val store = SecureSessionStore(this)
        repository = EventMenuRepository(
            baseUrl = BuildConfig.API_BASE_URL,
            deviceId = DeviceIdentity.id(this),
            sessionStore = store,
        )
    }
}
