cmake_minimum_required(VERSION 3.22.1)
project(eventmenu_node)

add_library(eventmenu_node SHARED src/main/cpp/native-lib.cpp)
target_compile_features(eventmenu_node PRIVATE cxx_std_20)
set_target_properties(eventmenu_node PROPERTIES
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED YES
    CXX_EXTENSIONS NO
)

add_library(node SHARED IMPORTED)
set_target_properties(node PROPERTIES
    IMPORTED_LOCATION "${CMAKE_SOURCE_DIR}/libnode/bin/${ANDROID_ABI}/libnode.so"
)

target_include_directories(eventmenu_node PRIVATE
    "${CMAKE_SOURCE_DIR}/libnode/include/node"
)

find_library(log-lib log)

target_link_libraries(eventmenu_node
    node
    ${log-lib}
)
