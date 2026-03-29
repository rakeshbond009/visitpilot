package expo.modules;

import java.util.Arrays;
import java.util.List;
import expo.modules.core.interfaces.Package;
import expo.modules.kotlin.modules.Module;
import expo.modules.kotlin.ModulesProvider;

public class ExpoModulesPackageList implements ModulesProvider {
  private static class LazyHolder {
    static final List<Package> packagesList = Arrays.<Package>asList(
      new expo.modules.adapters.react.ReactAdapterPackage(),
      new expo.modules.av.AVPackage(),
      new expo.modules.constants.ConstantsPackage(),
      new expo.modules.core.BasePackage(),
      new expo.modules.filesystem.FileSystemPackage(),
      new expo.modules.filesystem.FileSystemPackage(),
      new expo.modules.keepawake.KeepAwakePackage(),
      new expo.modules.keepawake.KeepAwakePackage(),
      new expo.modules.notifications.NotificationsPackage(),
      new expo.modules.taskManager.TaskManagerPackage()
    );

    static final List<Class<? extends Module>> modulesList = Arrays.<Class<? extends Module>>asList(
            expo.modules.application.ApplicationModule.class,
      expo.modules.asset.AssetModule.class,
      expo.modules.av.video.VideoViewModule.class,
      expo.modules.av.AVModule.class,
      expo.modules.backgroundfetch.BackgroundFetchModule.class,
      expo.modules.blur.BlurModule.class,
      expo.modules.camera.CameraViewModule.class,
      expo.modules.camera.legacy.CameraViewLegacyModule.class,
      expo.modules.constants.ConstantsModule.class,
      expo.modules.device.DeviceModule.class,
      expo.modules.filesystem.FileSystemModule.class,
      expo.modules.font.FontLoaderModule.class,
      expo.modules.keepawake.KeepAwakeModule.class,
      expo.modules.medialibrary.MediaLibraryModule.class,
      expo.modules.notifications.badge.BadgeModule.class,
      expo.modules.notifications.notifications.background.ExpoBackgroundNotificationTasksModule.class,
      expo.modules.notifications.notifications.categories.ExpoNotificationCategoriesModule.class,
      expo.modules.notifications.notifications.channels.NotificationChannelGroupManagerModule.class,
      expo.modules.notifications.notifications.channels.NotificationChannelManagerModule.class,
      expo.modules.notifications.notifications.emitting.NotificationsEmitter.class,
      expo.modules.notifications.notifications.handling.NotificationsHandler.class,
      expo.modules.notifications.permissions.NotificationPermissionsModule.class,
      expo.modules.notifications.notifications.presentation.ExpoNotificationPresentationModule.class,
      expo.modules.notifications.notifications.scheduling.NotificationScheduler.class,
      expo.modules.notifications.serverregistration.ServerRegistrationModule.class,
      expo.modules.notifications.tokens.PushTokenModule.class,
      expo.modules.taskManager.TaskManagerModule.class
    );
  }

  public static List<Package> getPackageList() {
    return LazyHolder.packagesList;
  }

  @Override
  public List<Class<? extends Module>> getModulesList() {
    return LazyHolder.modulesList;
  }
}
