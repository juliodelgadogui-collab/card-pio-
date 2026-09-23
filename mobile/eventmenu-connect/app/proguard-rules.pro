# EventMenu Connect - regras de produção.
# O símbolo JNI em native-lib.cpp depende do nome exato da classe/método.
-keep class br.com.eventmenu.connect.EmbeddedNodeRuntime { *; }
-keepclasseswithmembernames class * {
    native <methods>;
}

# Mantém os componentes Android declarados no manifest e seus construtores.
-keep class br.com.eventmenu.connect.EventMenuConnectApplication { *; }
-keep class br.com.eventmenu.connect.ConnectWorkerService { *; }
-keep class br.com.eventmenu.connect.BootReceiver { *; }
-keep class br.com.eventmenu.connect.PackageUpdatedReceiver { *; }
