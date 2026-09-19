package br.com.eventmenu.delivery.ui

import androidx.compose.runtime.Composable

@Composable
internal fun SelectionContainer(content: @Composable () -> Unit) {
    androidx.compose.foundation.text.selection.SelectionContainer(content = content)
}
