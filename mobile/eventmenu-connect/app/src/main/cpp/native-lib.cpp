#include <jni.h>
#include <android/log.h>
#include <node.h>
#include <pthread.h>
#include <unistd.h>
#include <cstdlib>
#include <cstring>
#include <vector>

namespace {
int pipe_stdout[2];
int pipe_stderr[2];
pthread_t thread_stdout;
pthread_t thread_stderr;
const char *TAG = "EventMenu-Node";

void *stderr_thread(void *) {
    ssize_t size;
    char buffer[2048];
    while ((size = read(pipe_stderr[0], buffer, sizeof(buffer) - 1)) > 0) {
        if (buffer[size - 1] == '\n') --size;
        buffer[size] = 0;
        __android_log_write(ANDROID_LOG_ERROR, TAG, buffer);
    }
    return nullptr;
}

void *stdout_thread(void *) {
    ssize_t size;
    char buffer[2048];
    while ((size = read(pipe_stdout[0], buffer, sizeof(buffer) - 1)) > 0) {
        if (buffer[size - 1] == '\n') --size;
        buffer[size] = 0;
        __android_log_write(ANDROID_LOG_INFO, TAG, buffer);
    }
    return nullptr;
}

void redirect_stdio() {
    setvbuf(stdout, nullptr, _IONBF, 0);
    setvbuf(stderr, nullptr, _IONBF, 0);
    if (pipe(pipe_stdout) == 0) {
        dup2(pipe_stdout[1], STDOUT_FILENO);
        if (pthread_create(&thread_stdout, nullptr, stdout_thread, nullptr) == 0) pthread_detach(thread_stdout);
    }
    if (pipe(pipe_stderr) == 0) {
        dup2(pipe_stderr[1], STDERR_FILENO);
        if (pthread_create(&thread_stderr, nullptr, stderr_thread, nullptr) == 0) pthread_detach(thread_stderr);
    }
}
} // namespace

extern "C" JNIEXPORT jint JNICALL
Java_br_com_eventmenu_connect_EmbeddedNodeRuntime_startNodeWithArguments(
        JNIEnv *env,
        jobject,
        jobjectArray arguments) {
    const jsize argc = env->GetArrayLength(arguments);
    if (argc < 1) return -1;

    size_t total = 0;
    std::vector<jstring> refs;
    std::vector<const char *> utf;
    refs.reserve(argc);
    utf.reserve(argc);

    for (jsize i = 0; i < argc; ++i) {
        auto value = static_cast<jstring>(env->GetObjectArrayElement(arguments, i));
        const char *chars = env->GetStringUTFChars(value, nullptr);
        refs.push_back(value);
        utf.push_back(chars);
        total += std::strlen(chars) + 1;
    }

    auto *buffer = static_cast<char *>(std::calloc(total, sizeof(char)));
    if (!buffer) {
        for (jsize i = 0; i < argc; ++i) env->ReleaseStringUTFChars(refs[i], utf[i]);
        return -2;
    }

    std::vector<char *> argv(argc);
    char *cursor = buffer;
    for (jsize i = 0; i < argc; ++i) {
        const size_t length = std::strlen(utf[i]);
        std::memcpy(cursor, utf[i], length);
        argv[i] = cursor;
        cursor += length + 1;
        env->ReleaseStringUTFChars(refs[i], utf[i]);
        env->DeleteLocalRef(refs[i]);
    }

    redirect_stdio();
    const int result = node::Start(static_cast<int>(argc), argv.data());
    std::free(buffer);
    return result;
}
