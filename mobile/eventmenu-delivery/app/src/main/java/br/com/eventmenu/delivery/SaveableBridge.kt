package br.com.eventmenu.delivery

import androidx.compose.runtime.Composable
import androidx.compose.runtime.saveable.rememberSaveable as rememberAndroidSaveable

@Composable
fun <T : Any> rememberSaveable(init: () -> T): T = rememberAndroidSaveable(init = init)
