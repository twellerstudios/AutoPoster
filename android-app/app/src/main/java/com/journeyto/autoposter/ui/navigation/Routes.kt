package com.journeyto.autoposter.ui.navigation

object Routes {
    const val LOGIN = "login"
    const val POST_LIST = "posts"
    const val EDITOR_NEW = "editor/new"
    const val EDITOR_EDIT = "editor/edit/{postId}"
    const val SETTINGS = "settings"

    fun editPostRoute(postId: Int) = "editor/edit/$postId"
}
