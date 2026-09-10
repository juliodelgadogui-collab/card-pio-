using System.ComponentModel;
using System.Windows.Controls;
using System.Windows.Media;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _dashboardUxReady;
    private DependencyPropertyDescriptor? _dashboardOrdersDescriptor;
    private DependencyPropertyDescriptor? _dashboardTablesDescriptor;
    private TextBlock? _dashboardAttentionTitle;
    private TextBlock? _dashboardAttentionDetail;
    private TextBlock? _dashboardAttentionIcon;

    private void EnsureDashboardUx()
    {
        if (_dashboardUxReady) return;
        _dashboardUxReady = true;

        RenameMetric(OrdersCountText, "Pedidos ativos", "em andamento agora");
        RenameMetric(ProductsCountText, "A receber", "pagamentos pendentes");
        RenameMetric(TablesCountText, "Prontos", "aguardando próxima etapa");

        var attentionCard = DashboardView.Children.OfType<Border>().FirstOrDefault(x => Grid.GetRow(x) == 3);
        if (attentionCard?.Child is Grid attentionGrid)
        {
            _dashboardAttentionIcon = attentionGrid.Children.OfType<Border>()
                .Select(x => x.Child)
                .OfType<TextBlock>()
                .FirstOrDefault();
            var stack = attentionGrid.Children.OfType<StackPanel>().FirstOrDefault(x => Grid.GetColumn(x) == 1);
            if (stack is not null)
            {
                _dashboardAttentionTitle = stack.Children.OfType<TextBlock>().FirstOrDefault();
                _dashboardAttentionDetail = stack.Children.OfType<TextBlock>().Skip(1).FirstOrDefault();
            }
        }

        _dashboardOrdersDescriptor = DependencyPropertyDescriptor.FromProperty(ItemsControl.ItemsSourceProperty, typeof(DataGrid));
        _dashboardOrdersDescriptor?.AddValueChanged(OrdersGrid, DashboardSourceChanged);
        _dashboardTablesDescriptor = DependencyPropertyDescriptor.FromProperty(ItemsControl.ItemsSourceProperty, typeof(DataGrid));
        _dashboardTablesDescriptor?.AddValueChanged(TablesGrid, DashboardSourceChanged);
        RefreshDashboardUx();
    }

    private static void RenameMetric(TextBlock valueText, string title, string subtitle)
    {
        if (valueText.Parent is not StackPanel panel) return;
        var labels = panel.Children.OfType<TextBlock>().ToList();
        if (labels.Count > 0) labels[0].Text = title;
        if (labels.Count > 2) labels[2].Text = subtitle;
    }

    private void DashboardSourceChanged(object? sender, EventArgs e) => RefreshDashboardUx();

    private void RefreshDashboardUx()
    {
        if (!_dashboardUxReady) return;

        var activeOrders = _orders.Count(x => x.Status is not ("completed" or "cancelled"));
        var readyOrders = _orders.Count(x => x.Status == "ready");
        var pendingPayments = _orders.Count(x => x.PaymentStatus is "unpaid" or "pending" or "partially_paid" && x.Status != "cancelled");
        var deliveryWithoutDriver = _orders.Count(x => x.Channel == "delivery"
            && x.AssignedDeliveryUserId is null
            && x.Status is "confirmed" or "preparing" or "ready");
        var occupiedTables = _tables.Count(x => x.TabId is > 0 || x.Status == "occupied");

        OrdersCountText.Text = activeOrders.ToString();
        ProductsCountText.Text = pendingPayments.ToString();
        TablesCountText.Text = readyOrders.ToString();

        if (_dashboardAttentionTitle is null || _dashboardAttentionDetail is null) return;

        if (deliveryWithoutDriver > 0)
        {
            SetDashboardAttention(
                "!",
                $"{deliveryWithoutDriver} delivery sem entregador",
                $"Há pedido de entrega que precisa de atribuição. {readyOrders} pronto(s), {pendingPayments} pagamento(s) pendente(s) e {occupiedTables} mesa(s) ocupada(s).",
                true);
            return;
        }

        if (readyOrders > 0)
        {
            SetDashboardAttention(
                "✓",
                $"{readyOrders} pedido(s) pronto(s)",
                $"A expedição já pode seguir com esses pedidos. {activeOrders} pedido(s) continuam em andamento e {pendingPayments} têm valor a receber.",
                false);
            return;
        }

        if (pendingPayments > 0)
        {
            SetDashboardAttention(
                "$",
                $"{pendingPayments} pagamento(s) pendente(s)",
                $"Revise os pedidos antes do fechamento. {occupiedTables} mesa(s) estão em atendimento.",
                true);
            return;
        }

        SetDashboardAttention(
            "✓",
            "Operação sob controle",
            $"{activeOrders} pedido(s) em andamento e {occupiedTables} mesa(s) em atendimento. Nenhuma pendência crítica identificada nesta visão.",
            false);
    }

    private void SetDashboardAttention(string icon, string title, string detail, bool warning)
    {
        if (_dashboardAttentionIcon is not null)
        {
            _dashboardAttentionIcon.Text = icon;
            _dashboardAttentionIcon.Foreground = warning
                ? new SolidColorBrush(Color.FromRgb(217, 119, 6))
                : TryFindResource("AccentBrush") as Brush ?? Brushes.RoyalBlue;
        }
        if (_dashboardAttentionTitle is not null) _dashboardAttentionTitle.Text = title;
        if (_dashboardAttentionDetail is not null) _dashboardAttentionDetail.Text = detail;
    }

    private void DisposeDashboardUx()
    {
        if (!_dashboardUxReady) return;
        _dashboardOrdersDescriptor?.RemoveValueChanged(OrdersGrid, DashboardSourceChanged);
        _dashboardTablesDescriptor?.RemoveValueChanged(TablesGrid, DashboardSourceChanged);
        _dashboardOrdersDescriptor = null;
        _dashboardTablesDescriptor = null;
        _dashboardUxReady = false;
    }
}
