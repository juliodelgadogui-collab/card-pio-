using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

namespace EventMenu.Desktop.Services;

public sealed class RawPrinterService
{
    public void OpenCashDrawer(string printerName)
    {
        if(string.IsNullOrWhiteSpace(printerName))throw new InvalidOperationException("Configure a impressora da gaveta neste computador.");
        // ESC/POS: ESC p 0 25 250. A gaveta só é acionada após autorização do servidor/usuário.
        Write(printerName,new byte[]{0x1B,0x70,0x00,0x19,0xFA},"EventMenu - Abrir gaveta");
    }

    public void PrintText(string printerName,string text,string jobName="EventMenu")
    {
        if(string.IsNullOrWhiteSpace(printerName))throw new InvalidOperationException("Impressora não configurada.");
        if(string.IsNullOrWhiteSpace(text))throw new InvalidOperationException("Não há conteúdo para imprimir.");
        var encoding=Encoding.GetEncoding(850,EncoderFallback.ReplacementFallback,DecoderFallback.ReplacementFallback);
        var payload=new List<byte>{0x1B,0x40}; // inicializa ESC/POS
        payload.AddRange(encoding.GetBytes(text.Replace("\r\n","\n")));
        payload.AddRange(new byte[]{0x0A,0x0A,0x0A,0x1D,0x56,0x00});
        Write(printerName,payload.ToArray(),jobName);
    }

    private static void Write(string printerName,byte[] data,string jobName)
    {
        if(!OpenPrinter(printerName,out var handle,IntPtr.Zero)||handle==IntPtr.Zero)
            throw new Win32Exception(Marshal.GetLastWin32Error(),"Não foi possível abrir a impressora configurada.");
        try
        {
            var info=new DOC_INFO_1{pDocName=jobName,pDataType="RAW"};
            if(!StartDocPrinter(handle,1,ref info))throw new Win32Exception(Marshal.GetLastWin32Error(),"Não foi possível iniciar a impressão.");
            try
            {
                if(!StartPagePrinter(handle))throw new Win32Exception(Marshal.GetLastWin32Error(),"Não foi possível iniciar a página.");
                try
                {
                    var unmanaged=Marshal.AllocHGlobal(data.Length);
                    try
                    {
                        Marshal.Copy(data,0,unmanaged,data.Length);
                        if(!WritePrinter(handle,unmanaged,data.Length,out var written)||written!=data.Length)
                            throw new Win32Exception(Marshal.GetLastWin32Error(),"A impressora não recebeu todos os dados.");
                    }
                    finally{Marshal.FreeHGlobal(unmanaged);}
                }
                finally{EndPagePrinter(handle);}
            }
            finally{EndDocPrinter(handle);}
        }
        finally{ClosePrinter(handle);}
    }

    [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Unicode)]
    private struct DOC_INFO_1{[MarshalAs(UnmanagedType.LPWStr)]public string pDocName;[MarshalAs(UnmanagedType.LPWStr)]public string? pOutputFile;[MarshalAs(UnmanagedType.LPWStr)]public string pDataType;}

    [DllImport("winspool.drv",SetLastError=true,CharSet=CharSet.Unicode)]private static extern bool OpenPrinter(string pPrinterName,out IntPtr phPrinter,IntPtr pDefault);
    [DllImport("winspool.drv",SetLastError=true)]private static extern bool ClosePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv",SetLastError=true,CharSet=CharSet.Unicode)]private static extern bool StartDocPrinter(IntPtr hPrinter,int level,ref DOC_INFO_1 pDocInfo);
    [DllImport("winspool.drv",SetLastError=true)]private static extern bool EndDocPrinter(IntPtr hPrinter);
    [DllImport("winspool.drv",SetLastError=true)]private static extern bool StartPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv",SetLastError=true)]private static extern bool EndPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv",SetLastError=true)]private static extern bool WritePrinter(IntPtr hPrinter,IntPtr pBytes,int dwCount,out int dwWritten);
}
