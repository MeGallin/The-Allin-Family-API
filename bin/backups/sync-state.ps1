# Read-only Windows Cloud Files API. Does not read OneDrive credentials or change
# sync settings. IN_SYNC is the provider's acknowledgement of cloud content.
if(-not ('AllinBackup.CloudState' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
namespace AllinBackup {
 public static class CloudState {
  [StructLayout(LayoutKind.Sequential)] struct AttributeTag { public uint Attributes; public uint Tag; }
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool GetFileInformationByHandleEx(SafeFileHandle handle, int infoClass, out AttributeTag info, uint size);
  [DllImport("CldApi.dll")] static extern uint CfGetPlaceholderStateFromAttributeTag(uint attributes, uint tag);
  public static uint Read(string path) {
   using(var file=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.ReadWrite|FileShare.Delete)) {
    AttributeTag info;
    if(!GetFileInformationByHandleEx(file.SafeFileHandle,9,out info,8)) throw new IOException("Cannot read cloud file state.");
    return CfGetPlaceholderStateFromAttributeTag(info.Attributes,info.Tag);
   }
  }
 }
}
'@
}
function Test-BackupCloudSynced([string]$Path) {
    $state=[AllinBackup.CloudState]::Read($Path)
    return $state -ne [uint32]::MaxValue -and ($state -band 9) -eq 9
}
