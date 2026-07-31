package com.journeyto.autoposter.ui.navigation

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Article
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.ui.editor.PostEditorScreen
import com.journeyto.autoposter.ui.login.LoginScreen
import com.journeyto.autoposter.ui.posts.PostListScreen
import com.journeyto.autoposter.ui.settings.SettingsScreen

@Composable
fun AutoPosterNavHost(locator: ServiceLocator) {
    val navController = rememberNavController()
    val session by locator.session.collectAsState()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route

    val showBottomBar = session != null && currentRoute in setOf(Routes.POST_LIST, Routes.SETTINGS)

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                NavigationBar {
                    NavigationBarItem(
                        selected = currentRoute == Routes.POST_LIST,
                        onClick = { navController.navigate(Routes.POST_LIST) { popUpTo(Routes.POST_LIST) { inclusive = true } } },
                        icon = { Icon(Icons.Filled.Article, contentDescription = "Posts") },
                        label = { Text("Posts") },
                    )
                    NavigationBarItem(
                        selected = false,
                        onClick = { navController.navigate(Routes.EDITOR_NEW) },
                        icon = { Icon(Icons.Filled.Add, contentDescription = "New Post") },
                        label = { Text("New") },
                    )
                    NavigationBarItem(
                        selected = currentRoute == Routes.SETTINGS,
                        onClick = { navController.navigate(Routes.SETTINGS) { popUpTo(Routes.POST_LIST) } },
                        icon = { Icon(Icons.Filled.Settings, contentDescription = "Settings") },
                        label = { Text("Settings") },
                    )
                }
            }
        },
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = if (session != null) Routes.POST_LIST else Routes.LOGIN,
            modifier = Modifier.padding(padding),
        ) {
            composable(Routes.LOGIN) {
                LoginScreen(locator) {
                    navController.navigate(Routes.POST_LIST) { popUpTo(0) }
                }
            }
            composable(Routes.POST_LIST) {
                PostListScreen(
                    locator = locator,
                    onNewPost = { navController.navigate(Routes.EDITOR_NEW) },
                    onEditPost = { id -> navController.navigate(Routes.editPostRoute(id)) },
                )
            }
            composable(Routes.EDITOR_NEW) {
                PostEditorScreen(
                    locator = locator,
                    postId = null,
                    onBack = { navController.popBackStack() },
                    onSaved = { navController.popBackStack() },
                )
            }
            composable(
                Routes.EDITOR_EDIT,
                arguments = listOf(navArgument("postId") { type = NavType.IntType }),
            ) { entry ->
                val postId = entry.arguments?.getInt("postId") ?: 0
                PostEditorScreen(
                    locator = locator,
                    postId = postId,
                    onBack = { navController.popBackStack() },
                    onSaved = { navController.popBackStack() },
                )
            }
            composable(Routes.SETTINGS) {
                SettingsScreen(locator) {
                    navController.navigate(Routes.LOGIN) { popUpTo(0) }
                }
            }
        }
    }
}
