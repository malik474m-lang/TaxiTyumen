using System.Windows;

namespace TaxiOperator.Views;

public partial class DriverSelectWindow : Window
{
    public int SelectedIndex { get; private set; } = -1;

    public DriverSelectWindow(List<string> drivers)
    {
        InitializeComponent();
        DriversList.ItemsSource = drivers;
        if (drivers.Count > 0)
            DriversList.SelectedIndex = 0;
    }

    private void OnConfirmClick(object sender, RoutedEventArgs e)
    {
        if (DriversList.SelectedIndex < 0)
        {
            MessageBox.Show("Выберите водителя из списка", "Ошибка",
                MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        SelectedIndex = DriversList.SelectedIndex;
        DialogResult = true;
        Close();
    }

    private void OnCancelClick(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }
}